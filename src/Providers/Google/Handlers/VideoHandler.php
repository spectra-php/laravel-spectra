<?php

declare(strict_types=1);

namespace Spectra\Providers\Google\Handlers;

use Illuminate\Support\Facades\Http;
use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Contracts\ExtractsModelFromRequest;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasFinishReason;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Contracts\SkipsResponse;
use Spectra\Data\Metrics;
use Spectra\Data\VideoMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Google\ExtractsModelFromGoogleEndpoint;
use Spectra\Support\MediaPersister;

/**
 * Veo video generation: the `predictLongRunning` job and the polls of its
 * operation. Only the finished operation is recorded.
 *
 * The cost is per second of video, read from `parameters.durationSeconds` of
 * the request. A poll is a separate GET without that body, so the submit's
 * body is remembered under the operation name and merged back into the poll
 * that finishes the job.
 */
class VideoHandler implements ExtractsModelFromRequest, ExtractsModelFromResponse, Handler, HasFinishReason, HasMedia, MatchesResponseShape, SkipsResponse
{
    use ExtractsModelFromGoogleEndpoint;
    use MatchesParametricEndpoints;

    /** Seconds to wait for a generated video to download. */
    protected const MEDIA_DOWNLOAD_TIMEOUT_SECONDS = 60;

    public function modelType(): ModelType
    {
        return ModelType::Video;
    }

    public function endpoints(): array
    {
        return [
            '/{version}/models/{model}:predictLongRunning',
            '/{version}/models/{model}:fetchPredictOperation',
            // The Gemini API polls a Veo job with GET /v1beta/{operation.name}.
            '/{version}/models/{model}/operations/{operation}',
        ];
    }

    /** @param  array<string, mixed>  $responseData */
    public function shouldSkipResponse(array $responseData): bool
    {
        return ($responseData['done'] ?? false) !== true;
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $samples = $this->extractSamples($responseData);
        $videoCount = count($samples);

        $durationSeconds = null;
        $requestedDuration = $requestData['parameters']['durationSeconds']
            ?? $requestData['instances'][0]['parameters']['durationSeconds']
            ?? null;

        if ($requestedDuration !== null && $videoCount > 0) {
            $durationSeconds = (float) $requestedDuration * $videoCount;
        }

        return new Metrics(
            video: new VideoMetrics(
                count: $videoCount,
                durationSeconds: $durationSeconds,
            ),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractModelFromResponse(array $response): ?string
    {
        return filled($response['modelVersion'] ?? null) ? $response['modelVersion'] : null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        $samples = $this->extractSamples($response);

        if (blank($samples)) {
            return null;
        }

        $parts = [];

        foreach ($samples as $sample) {
            $uri = $sample['uri'] ?? null;

            if ($uri !== null) {
                $parts[] = $uri;
            } else {
                $parts[] = '[generated video]';
            }
        }

        return implode("\n", $parts);
    }

    /** @param  array<string, mixed>  $response */
    public function extractFinishReason(array $response): ?string
    {
        if (isset($response['done'])) {
            return $response['done'] ? 'COMPLETE' : 'PROCESSING';
        }

        return null;
    }

    /** @param  array<string, mixed>  $data */
    public function matchesResponse(array $data): bool
    {
        // Completed Veo response (either format)
        if (isset($data['response']['generateVideoResponse'])) {
            return true;
        }

        if (isset($data['response']['videos'])) {
            return true;
        }

        // Long-running operation (in-progress or completed)
        if (isset($data['name'], $data['done'])) {
            $name = $data['name'];

            if (is_string($name) && str_contains($name, 'veo')) {
                return true;
            }

            // Check for /models/{model}/operations/ pattern
            if (is_string($name) && preg_match('#/models/[^/]+/operations/#', $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        $samples = $this->extractSamples($responseData);

        if (blank($samples)) {
            return [];
        }

        $paths = [];

        foreach ($samples as $index => $sample) {
            $uri = $sample['uri'];

            if ($uri === null) {
                continue;
            }

            // One video that fails to download or store must not lose the others.
            $path = rescue(function () use ($requestId, $index, $uri) {
                $response = Http::withoutAITracking()->timeout(self::MEDIA_DOWNLOAD_TIMEOUT_SECONDS)->get($uri);

                return $response->successful()
                    ? app(MediaPersister::class)->store($requestId, $index, $response->body(), 'mp4')
                    : null;
            });

            if ($path !== null) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * Extract video samples from either response format.
     *
     * Supports:
     * - `response.generateVideoResponse.generatedSamples[].video.{uri,gcsUri}`
     * - `response.videos[].{gcsUri,uri}`
     *
     * @param  array<string, mixed>  $responseData
     * @return array<int, array{uri: string|null, mimeType: string}>
     */
    protected function extractSamples(array $responseData): array
    {
        // Format 1: generateVideoResponse.generatedSamples
        $samples = data_get($responseData, 'response.generateVideoResponse.generatedSamples');

        if (is_array($samples) && $samples !== []) {
            return $this->videoSamples($samples, 'video.uri', 'video.gcsUri', 'video.mimeType');
        }

        // Format 2: videos[]
        $videos = data_get($responseData, 'response.videos');

        if (is_array($videos) && $videos !== []) {
            return $this->videoSamples($videos, 'gcsUri', 'uri', 'mimeType');
        }

        return [];
    }

    /**
     * @param  array<mixed>  $items
     * @return array<int, array{uri: string|null, mimeType: string}>
     */
    protected function videoSamples(array $items, string $uriKey, string $fallbackUriKey, string $mimeTypeKey): array
    {
        return collect($items)
            ->filter(fn (mixed $item) => is_array($item))
            ->values()
            ->map(function (array $item) use ($uriKey, $fallbackUriKey, $mimeTypeKey) {
                $uri = data_get($item, $uriKey) ?? data_get($item, $fallbackUriKey);

                return [
                    'uri' => filled($uri) ? $uri : null,
                    'mimeType' => data_get($item, $mimeTypeKey) ?? 'video/mp4',
                ];
            })
            ->all();
    }
}
