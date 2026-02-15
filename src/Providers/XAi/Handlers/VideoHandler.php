<?php

namespace Spectra\Providers\XAi\Handlers;

use Illuminate\Support\Facades\Http;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\SkipsResponse;
use Spectra\Data\Metrics;
use Spectra\Data\VideoMetrics;
use Spectra\Enums\ModelType;
use Spectra\Support\MediaPersister;

class VideoHandler implements Handler, HasMedia, SkipsResponse
{
    public function modelType(): ModelType
    {
        return ModelType::Video;
    }

    public function endpoints(): array
    {
        return [
            '/v1/videos/generations',
            '/v1/videos/generations/{request_id}',
        ];
    }

    public function matchesEndpoint(string $endpoint): bool
    {
        return $endpoint === '/v1/videos/generations'
            || (bool) preg_match('#^/v1/videos/generations/[^/]+$#', $endpoint);
    }

    /** @param  array<string, mixed>  $responseData */
    public function shouldSkipResponse(array $responseData): bool
    {
        return ($responseData['status'] ?? null) !== 'done';
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        return new Metrics(
            video: new VideoMetrics(
                count: 1,
                durationSeconds: isset($responseData['video']['duration']) ? (float) $responseData['video']['duration'] : null,
            ),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractModel(array $response): ?string
    {
        return $response['model'] ?? null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        return $response['video']['url'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        $url = $responseData['video']['url'] ?? null;

        if (! $url) {
            return [];
        }

        $response = Http::withoutAITracking()->get($url);

        if (! $response->successful()) {
            return [];
        }

        return [app(MediaPersister::class)->store($requestId, 0, $response->body(), 'mp4')];
    }
}
