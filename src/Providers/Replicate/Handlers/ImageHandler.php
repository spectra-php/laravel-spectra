<?php

declare(strict_types=1);

namespace Spectra\Providers\Replicate\Handlers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spectra\Contracts\ExtractsModelFromRequest;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasFinishReason;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\HasProviderLatency;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Contracts\ReportsJobFailure;
use Spectra\Contracts\SkipsResponse;
use Spectra\Data\ImageMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Replicate\Concerns\HandlesPredictions;
use Spectra\Support\MediaPersister;
use Spectra\Support\Payload;

/**
 * Replicate predictions that produce images.
 *
 * Image models return one URL (FLUX 1.1 Pro, Ideogram, Recraft) or a list of
 * URLs. The count comes from `metrics.image_count` when Replicate reports
 * it, otherwise from the image URLs in the output.
 */
class ImageHandler implements ExtractsModelFromRequest, ExtractsModelFromResponse, Handler, HasFinishReason, HasMedia, HasProviderLatency, MatchesResponseShape, ReportsJobFailure, SkipsResponse
{
    use HandlesPredictions;

    /** Seconds to wait for a generated image to download. */
    protected const MEDIA_DOWNLOAD_TIMEOUT_SECONDS = 30;

    /**
     * Get the model type Replicate image predictions are recorded as.
     */
    public function modelType(): ModelType
    {
        return ModelType::Image;
    }

    /**
     * Build the image count from `metrics.image_count` or the output image URLs.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $reported = $responseData['metrics']['image_count'] ?? null;

        return new Metrics(
            image: new ImageMetrics(
                count: is_numeric($reported) ? Payload::int($reported) : count($this->imageUrls($responseData)),
            ),
        );
    }

    /**
     * Get the output URLs of a Replicate prediction, one per line.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        $urls = $this->outputUrls($response);

        return filled($urls) ? implode("\n", $urls) : null;
    }

    /**
     * Determine if the prediction produced images.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchesResponse(array $data): bool
    {
        return $this->predictionKind($data) === 'image';
    }

    /**
     * Download and store every output image, skipping any that fail.
     *
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        $persister = app(MediaPersister::class);
        $stored = [];

        foreach ($this->imageUrls($responseData) as $index => $url) {
            // One image that fails to download or store must not lose the others.
            $path = rescue(function () use ($persister, $requestId, $index, $url) {
                $response = Http::withoutAITracking()->timeout(self::MEDIA_DOWNLOAD_TIMEOUT_SECONDS)->get($url);

                if (! $response->successful()) {
                    return null;
                }

                $extension = $this->resolveExtensionFromContentType($response->header('Content-Type'));

                return $persister->store($requestId, $index, $response->body(), $extension);
            });

            if ($path !== null) {
                $stored[] = $path;
            }
        }

        return $stored;
    }

    /**
     * Get the output URLs that are not clearly another kind of file (audio, video).
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    protected function imageUrls(array $data): array
    {
        return collect($this->outputUrls($data))
            ->reject(fn (string $url): bool => in_array($this->fileKind($url), ['video', 'audio'], true))
            ->values()
            ->all();
    }

    /**
     * Get the file extension for an image content type, defaulting to png.
     */
    private function resolveExtensionFromContentType(?string $contentType): string
    {
        if (filled($contentType)) {
            $mime = Str::lower(trim(Str::before($contentType, ';')));

            return match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                default => 'png',
            };
        }

        return 'png';
    }
}
