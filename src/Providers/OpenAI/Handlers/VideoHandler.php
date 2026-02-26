<?php

namespace Spectra\Providers\OpenAI\Handlers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasExpiration;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\SkipsResponse;
use Spectra\Data\Metrics;
use Spectra\Data\VideoMetrics;
use Spectra\Enums\ModelType;
use Spectra\Support\ApiKeyResolver;
use Spectra\Support\MediaPersister;

class VideoHandler implements ExtractsModelFromResponse, Handler, HasExpiration, HasMedia, SkipsResponse
{
    use MatchesParametricEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Video;
    }

    public function endpoints(): array
    {
        return [
            '/v1/videos',
            '/v1/videos/{id}',
        ];
    }

    /** @param  array<string, mixed>  $responseData */
    public function shouldSkipResponse(array $responseData): bool
    {
        return ($responseData['status'] ?? null) !== 'completed';
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
                durationSeconds: isset($responseData['seconds']) ? (float) $responseData['seconds'] : null,
            ),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractModelFromResponse(array $response): ?string
    {
        return $response['model'] ?? null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        return $response['prompt'] ?? null;
    }

    /** @param  array<string, mixed>  $responseData */
    public function extractExpiresAt(array $responseData): ?Carbon
    {
        return isset($responseData['expires_at'])
            ? Carbon::createFromTimestamp($responseData['expires_at'])
            : null;
    }

    /**
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        $id = $responseData['id'] ?? null;
        if (! $id) {
            return [];
        }

        $apiKey = app(ApiKeyResolver::class)->resolve('openai');
        if (! $apiKey) {
            return [];
        }

        $url = "https://api.openai.com/v1/videos/{$id}/content";
        $response = Http::withoutAITracking()->withToken($apiKey)->get($url);

        if (! $response->successful()) {
            return [];
        }

        return [app(MediaPersister::class)->store($requestId, 0, $response->body(), 'mp4')];
    }
}
