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

    /** Seconds to wait for a generated video to download. */
    protected const MEDIA_DOWNLOAD_TIMEOUT_SECONDS = 60;

    /**
     * Get the model type this handler records.
     */
    public function modelType(): ModelType
    {
        return ModelType::Video;
    }

    /**
     * Get the video submit and poll endpoint paths.
     */
    public function endpoints(): array
    {
        return [
            '/v1/videos',
            '/v1/videos/{id}',
        ];
    }

    /**
     * Determine if the video job has not completed and should not be recorded yet.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function shouldSkipResponse(array $responseData): bool
    {
        return ($responseData['status'] ?? null) !== 'completed';
    }

    /**
     * Get the video count and length of a completed job.
     *
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

    /**
     * Get the job's prompt as the response text.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        return $response['prompt'] ?? null;
    }

    /**
     * Get when the generated video expires, from `expires_at`.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function extractExpiresAt(array $responseData): ?CarbonInterface
    {
        $expiresAt = $responseData['expires_at'] ?? null;

        return is_numeric($expiresAt) ? Carbon::createFromTimestamp((int) $expiresAt) : null;
    }

    /**
     * Download and store the generated video and return its path.
     *
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        $id = $responseData['id'] ?? null;
        if (blank($id)) {
            return [];
        }

        $apiKey = app(ApiKeyResolver::class)->resolve('openai');
        if (! $apiKey) {
            return [];
        }

        $path = rescue(function () use ($requestId, $id, $apiKey) {
            $response = Http::withoutAITracking()
                ->timeout(self::MEDIA_DOWNLOAD_TIMEOUT_SECONDS)
                ->withToken($apiKey)
                ->get("https://api.openai.com/v1/videos/{$id}/content");

            return $response->successful()
                ? app(MediaPersister::class)->store($requestId, 0, $response->body(), 'mp4')
                : null;
        });

        return $path === null ? [] : [$path];
    }
}
