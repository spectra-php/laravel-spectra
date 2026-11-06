<?php

declare(strict_types=1);

namespace Spectra\Providers\XAi\Handlers;

use Illuminate\Support\Facades\Http;
use Spectra\Concerns\ExtractsModelField;
use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\SkipsResponse;
use Spectra\Data\Metrics;
use Spectra\Data\VideoMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\XAi\XAiUsage;
use Spectra\Support\MediaPersister;
use Spectra\Support\Payload;

/**
 * xAI video generation, editing and extension (Grok Imagine Video).
 *
 * All three POST endpoints only return a `request_id`; the video is fetched
 * by polling `GET /v1/videos/{request_id}` until `status` is `done`. Only that
 * final poll is recorded. It carries no id of its own, so the provider uses
 * the request id from the path as the response id, which keeps repeated polls
 * on one row. The poll reports the exact charge in `usage.cost_in_usd_ticks`,
 * which covers the resolution the request asked for.
 */
class VideoHandler implements ExtractsModelFromResponse, Handler, HasMedia, SkipsResponse
{
    use ExtractsModelField;
    use MatchesParametricEndpoints;

    /** Seconds to wait for a generated video to download. */
    protected const MEDIA_DOWNLOAD_TIMEOUT_SECONDS = 60;

    /**
     * Get the model type xAI video requests are recorded as.
     */
    public function modelType(): ModelType
    {
        return ModelType::Video;
    }

    /**
     * Get the xAI video generation, edit, extension and poll endpoint paths.
     */
    public function endpoints(): array
    {
        return [
            '/v1/videos/generations',
            '/v1/videos/edits',
            '/v1/videos/extensions',
            '/v1/videos/{request_id}',
        ];
    }

    /**
     * Skip the `{request_id}` acknowledgements, `pending` polls and `failed`
     * jobs (no video, no charge).
     *
     * @param  array<string, mixed>  $responseData
     */
    public function shouldSkipResponse(array $responseData): bool
    {
        return ($responseData['status'] ?? null) !== 'done';
    }

    /**
     * Build the video length, prompt-rewriting tokens and reported cost of a finished xAI video.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $usage = is_array($responseData['usage'] ?? null) ? $responseData['usage'] : [];
        $duration = $responseData['video']['duration'] ?? null;

        return new Metrics(
            tokens: XAiUsage::mediaTokens($usage),
            video: new VideoMetrics(
                count: 1,
                durationSeconds: is_numeric($duration) ? Payload::float($duration) : null,
            ),
            reportedCostInCents: XAiUsage::costInCents($usage),
        );
    }

    /**
     * Get the URL of the generated xAI video.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        $url = $response['video']['url'] ?? null;

        return filled($url) ? $url : null;
    }

    /**
     * Download and store the generated xAI video as an mp4 file.
     *
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        $url = $responseData['video']['url'] ?? null;

        if (blank($url)) {
            return [];
        }

        $path = rescue(function () use ($requestId, $url) {
            $response = Http::withoutAITracking()->timeout(self::MEDIA_DOWNLOAD_TIMEOUT_SECONDS)->get($url);

            return $response->successful()
                ? app(MediaPersister::class)->store($requestId, 0, $response->body(), 'mp4')
                : null;
        });

        return $path === null ? [] : [$path];
    }
}
