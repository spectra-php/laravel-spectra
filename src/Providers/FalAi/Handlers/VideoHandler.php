<?php

declare(strict_types=1);

namespace Spectra\Providers\FalAi\Handlers;

use Illuminate\Support\Facades\Http;
use Spectra\Contracts\ExtractsModelFromRequest;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasFinishReason;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Contracts\SkipsResponse;
use Spectra\Data\Metrics;
use Spectra\Data\VideoMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\FalAi\Concerns\HandlesFalRequests;
use Spectra\Support\MediaPersister;

/**
 * fal.ai video models (Kling, Veo, Seedance, MiniMax, ...), whose results
 * carry a `video` file instead of `images`.
 *
 * Per-second models need the length, which only the request states
 * (`duration`, e.g. `5` or `"8s"`, or `num_frames`, which fal bills as
 * seconds at 16 frames per second). A queued result is fetched with a
 * body-less GET, so it is measured with its submit request's body.
 */
class VideoHandler implements ExtractsModelFromRequest, Handler, HasFinishReason, HasMedia, MatchesResponseShape, SkipsResponse
{
    use HandlesFalRequests;

    /** Frames in one billed second of a model that takes `num_frames`. */
    protected const BILLED_FPS = 16;

    public function modelType(): ModelType
    {
        return ModelType::Video;
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        return new Metrics(
            video: new VideoMetrics(
                count: count($this->videos($responseData)),
                durationSeconds: $this->durationSeconds($requestData),
            ),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        $urls = array_filter(array_map(
            fn (array $video) => is_string($video['url'] ?? null) ? $video['url'] : null,
            $this->videos($response),
        ));

        return $urls !== [] ? implode("\n", $urls) : null;
    }

    /** @param  array<string, mixed>  $data */
    public function matchesResponse(array $data): bool
    {
        return $this->videos($data) !== [];
    }

    /**
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        $persister = app(MediaPersister::class);
        $stored = [];

        foreach ($this->videos($responseData) as $i => $video) {
            $url = $video['url'] ?? null;

            if (! is_string($url)) {
                continue;
            }

            $response = Http::withoutAITracking()->get($url);

            if (! $response->successful()) {
                continue;
            }

            $type = strtolower((string) ($video['content_type'] ?? $response->header('Content-Type')));
            $extension = str_contains($type, 'webm') ? 'webm' : (str_contains($type, 'quicktime') ? 'mov' : 'mp4');
            $stored[] = $persister->store($requestId, $i, $response->body(), $extension);
        }

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    protected function videos(array $data): array
    {
        $output = $this->output($data);

        if (is_array($output['video'] ?? null) && isset($output['video']['url'])) {
            return [$output['video']];
        }

        $videos = $output['videos'] ?? [];

        return is_array($videos) ? array_values(array_filter($videos, fn ($video) => is_array($video) && isset($video['url']))) : [];
    }

    /** @param  array<string, mixed>  $requestData */
    protected function durationSeconds(array $requestData): ?float
    {
        $duration = $requestData['duration'] ?? null;

        if (is_string($duration)) {
            $duration = rtrim(trim($duration), 's');
        }

        if (is_numeric($duration) && (float) $duration > 0) {
            return (float) $duration;
        }

        $frames = $requestData['num_frames'] ?? null;

        return is_numeric($frames) && (float) $frames > 0 ? (float) $frames / self::BILLED_FPS : null;
    }
}
