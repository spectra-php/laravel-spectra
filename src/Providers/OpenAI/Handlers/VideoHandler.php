<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenAI\Handlers;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spectra\Concerns\ExtractsModelField;
use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasExpiration;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\HasProviderLatency;
use Spectra\Contracts\SkipsResponse;
use Spectra\Data\Metrics;
use Spectra\Data\VideoMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\OpenAI\MeasuresJobLatency;
use Spectra\Support\ApiKeyResolver;
use Spectra\Support\MediaPersister;

/**
 * Sora video generation (`/v1/videos`), billed per second of video.
 *
 * OpenAI shut the Videos API down on 2026-09-24; the handler stays so
 * existing integrations and history keep working. Jobs are polled: only the
 * completed job is recorded, under the video id, with the job's own
 * generation time as latency.
 */
class VideoHandler implements ExtractsModelFromResponse, Handler, HasExpiration, HasMedia, HasProviderLatency, SkipsResponse
{
    use ExtractsModelField;
    use MatchesParametricEndpoints;
    use MeasuresJobLatency;

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
    public function extractResponse(array $response): ?string
    {
        return $response['prompt'] ?? null;
    }

    /** @param  array<string, mixed>  $responseData */
    public function extractExpiresAt(array $responseData): ?CarbonInterface
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
