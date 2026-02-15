<?php

namespace Spectra\Providers\Google\Handlers;

use Illuminate\Support\Facades\Http;
use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Contracts\ExtractsModelFromRequest;
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

class VideoHandler implements ExtractsModelFromRequest, Handler, HasFinishReason, HasMedia, MatchesResponseShape, SkipsResponse
{
    use ExtractsModelFromGoogleEndpoint;
    use MatchesParametricEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Video;
    }

    public function endpoints(): array
    {
        return [
            '/{version}/models/{model}:predictLongRunning',
            '/{version}/models/{model}:fetchPredictOperation',
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
    public function extractModel(array $response): ?string
    {
        return $response['modelVersion'] ?? null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        $samples = $this->extractSamples($response);

        if (empty($samples)) {
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

        if (empty($samples)) {
            return [];
        }

        $paths = [];

        foreach ($samples as $index => $sample) {
            $uri = $sample['uri'] ?? null;

            if ($uri === null) {
                continue;
            }

            $response = Http::withoutAITracking()->get($uri);

            if (! $response->successful()) {
                continue;
            }

            $paths[] = app(MediaPersister::class)->store($requestId, $index, $response->body(), 'mp4');
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
        $samples = $responseData['response']['generateVideoResponse']['generatedSamples'] ?? [];

        if (! empty($samples)) {
            return array_map(fn (array $sample) => [
                'uri' => $sample['video']['uri'] ?? $sample['video']['gcsUri'] ?? null,
                'mimeType' => $sample['video']['mimeType'] ?? 'video/mp4',
            ], $samples);
        }

        // Format 2: videos[]
        $videos = $responseData['response']['videos'] ?? [];

        if (! empty($videos)) {
            return array_map(fn (array $video) => [
                'uri' => $video['gcsUri'] ?? $video['uri'] ?? null,
                'mimeType' => $video['mimeType'] ?? 'video/mp4',
            ], $videos);
        }

        return [];
    }
}
