<?php

declare(strict_types=1);

namespace Spectra\Providers\Replicate\Concerns;

use Carbon\CarbonImmutable;
use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Support\Pricing\PricingLookup;
use Throwable;

/**
 * What every Replicate prediction handler shares: the prediction endpoints,
 * the async lifecycle, the model and timing fields, and the decision which
 * handler (image, video or text) a prediction belongs to.
 *
 * A prediction is created with `POST` (it comes back `starting` unless the
 * request waited with `Prefer: wait`) and polled with
 * `GET /v1/predictions/{id}`. Only finished predictions are recorded, under
 * the prediction id, so repeated polls update the same row.
 */
trait HandlesPredictions
{
    use MatchesParametricEndpoints;

    /**
     * Get the Replicate prediction create, poll and cancel endpoint paths.
     *
     * @return array<string>
     */
    public function endpoints(): array
    {
        return [
            '/v1/models/{owner}/{model}/predictions',
            '/v1/deployments/{owner}/{deployment}/predictions',
            '/v1/predictions',
            '/v1/predictions/{id}',
            '/v1/predictions/{id}/cancel',
        ];
    }

    /**
     * `starting` and `processing` predictions are still running; the poll
     * that sees them finish is recorded instead. Bodies without a status are
     * not predictions (e.g. the prediction list, `GET /v1/predictions`).
     *
     * @param  array<string, mixed>  $responseData
     */
    public function shouldSkipResponse(array $responseData): bool
    {
        $status = $responseData['status'] ?? null;

        return ! is_string($status) || in_array($status, ['starting', 'processing'], true);
    }

    /**
     * Get the model a Replicate prediction reports.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractModelFromResponse(array $response): ?string
    {
        return filled($response['model'] ?? null) ? $response['model'] : null;
    }

    /**
     * The model named in the request path (official models) or in a
     * `version` of the form `owner/model:version`. Deployments and polls
     * only learn the model from the response.
     *
     * @param  array<string, mixed>  $requestData
     */
    public function extractModelFromRequest(array $requestData, string $endpoint): ?string
    {
        if (preg_match('#^/v1/models/([^/]+)/([^/]+)/predictions$#', $endpoint, $matches)) {
            return "{$matches[1]}/{$matches[2]}";
        }

        $version = $requestData['version'] ?? null;

        if ($endpoint === '/v1/predictions' && is_string($version) && str_contains($version, '/')) {
            return explode(':', $version, 2)[0];
        }

        return null;
    }

    /**
     * A prediction that ended `failed` or `canceled` (still HTTP 200).
     *
     * @param  array<string, mixed>  $responseData
     */
    public function extractFailure(array $responseData): ?string
    {
        $status = $responseData['status'] ?? null;

        if (! in_array($status, ['failed', 'canceled'], true)) {
            return null;
        }

        $error = $responseData['error'] ?? null;

        return filled($error) ? $error : "Prediction {$status}";
    }

    /**
     * Get the status of a Replicate prediction as its finish reason.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractFinishReason(array $response): ?string
    {
        return is_string($response['status'] ?? null) ? $response['status'] : null;
    }

    /**
     * How long the model ran: `completed_at` minus `started_at` (or
     * `created_at`), else `metrics.predict_time`. The HTTP call that fetched
     * the result only measured a poll.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function extractProviderLatency(array $responseData): ?int
    {
        $completed = $this->timestamp($responseData['completed_at'] ?? null);
        $started = $this->timestamp($responseData['started_at'] ?? null)
            ?? $this->timestamp($responseData['created_at'] ?? null);

        if ($completed !== null && $started !== null && $completed >= $started) {
            return (int) round($started->diffInMilliseconds($completed));
        }

        $predictTime = $responseData['metrics']['predict_time'] ?? null;

        return is_numeric($predictTime) ? (int) round((float) $predictTime * 1000) : null;
    }

    /**
     * Which kind of output a prediction produced: 'text', 'image' or
     * 'video', or null when it can't tell (the first handler then takes it).
     *
     * Token counts mean text. Otherwise the catalog type of the model wins,
     * then the file extension of the output URLs, then the output shape.
     *
     * @param  array<string, mixed>  $data
     */
    protected function predictionKind(array $data): ?string
    {
        if (! isset($data['urls'], $data['status'])) {
            return null;
        }

        $metrics = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];

        if (isset($metrics['input_token_count']) || isset($metrics['output_token_count'])) {
            return 'text';
        }

        $model = $this->extractModelFromResponse($data);
        $catalogType = $model !== null ? app(PricingLookup::class)->getModelType('replicate', $model) : null;

        if (in_array($catalogType, ['text', 'image', 'video'], true)) {
            return $catalogType;
        }

        $output = $data['output'] ?? null;
        $first = is_array($output) ? ($output[0] ?? null) : $output;

        // Only a string output can be text or a file URL.
        if (! is_string($first)) {
            return null;
        }

        if (! $this->isUrl($first)) {
            return 'text';
        }

        return match ($this->fileKind($first)) {
            'video' => 'video',
            'image' => 'image',
            // Unknown files: a list is most often images, a single file video.
            default => is_array($output) ? 'image' : null,
        };
    }

    /**
     * The output URLs of a prediction (a single URL or a list of them).
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    protected function outputUrls(array $data): array
    {
        $output = $data['output'] ?? null;
        $items = is_array($output) ? $output : [$output];

        return collect($items)->filter(fn (mixed $item) => is_string($item) && $this->isUrl($item))->values()->all();
    }

    /**
     * The prediction input: echoed in every response, so it is also there
     * for polls, which send no body.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     * @return array<string, mixed>
     */
    protected function predictionInput(array $requestData, array $responseData): array
    {
        $input = $responseData['input'] ?? $requestData['input'] ?? [];

        return is_array($input) ? $input : [];
    }

    /** 'image', 'video', 'audio' or null, from the URL's file extension. */
    protected function fileKind(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $extension = is_string($path) ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';

        return match ($extension) {
            'png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'bmp', 'tif', 'tiff', 'avif', 'heic' => 'image',
            'mp4', 'webm', 'mov', 'm4v', 'mkv', 'avi' => 'video',
            'mp3', 'wav', 'ogg', 'flac', 'm4a', 'aac' => 'audio',
            default => null,
        };
    }

    /**
     * Determine if a prediction output value is a URL.
     */
    protected function isUrl(string $value): bool
    {
        return (bool) filter_var($value, FILTER_VALIDATE_URL);
    }

    /**
     * Parse a prediction timestamp, or return null when it is blank or invalid.
     */
    protected function timestamp(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
