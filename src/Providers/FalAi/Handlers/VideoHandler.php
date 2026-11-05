<?php

declare(strict_types=1);

namespace Spectra\Providers\FalAi\Handlers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
use Spectra\Support\Payload;

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

    /** Seconds to wait for a generated video to download. */
    protected const MEDIA_DOWNLOAD_TIMEOUT_SECONDS = 60;

    /**
     * Get the model type fal.ai video requests are recorded as.
     */
    public function modelType(): ModelType
    {
        return ModelType::Video;
    }

    /**
     * Build the video count and requested length of a fal.ai result.
     *
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

    /**
     * Get the URLs of the generated videos, one per line.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        $urls = collect($this->videos($response))
            ->map(fn (array $video) => is_string($video['url'] ?? null) ? $video['url'] : null)
            ->filter()
            ->all();

        return $urls !== [] ? implode("\n", $urls) : null;
    }

    /**
     * Determine if the fal.ai result holds a video.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchesResponse(array $data): bool
    {
        return $this->videos($data) !== [];
    }

    /**
     * Download and store every generated video, skipping any that fail.
     *
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        $persister = app(MediaPersister::class);
        $stored = [];

        foreach ($this->videos($responseData) as $index => $video) {
            $url = $video['url'] ?? null;

            if (! is_string($url)) {
                continue;
            }

            // One video that fails to download or store must not lose the others.
            $path = rescue(function () use ($persister, $requestId, $index, $url, $video) {
                $response = Http::withoutAITracking()->timeout(self::MEDIA_DOWNLOAD_TIMEOUT_SECONDS)->get($url);

                if (! $response->successful()) {
                    return null;
                }

                $type = Str::lower($video['content_type'] ?? $response->header('Content-Type'));

                $extension = match (true) {
                    Str::contains($type, 'webm') => 'webm',
                    Str::contains($type, 'quicktime') => 'mov',
                    default => 'mp4',
                };

                return $persister->store($requestId, $index, $response->body(), $extension);
            });

            if ($path !== null) {
                $stored[] = $path;
            }
        }

        return $stored;
    }

    /**
     * Get the video files of a result (`video`, or `videos[]`) that have a URL.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<mixed>>
     */
    protected function videos(array $data): array
    {
        $output = $this->output($data);

        if (is_array($output['video'] ?? null) && isset($output['video']['url'])) {
            return [$output['video']];
        }

        $videos = $output['videos'] ?? [];

        return is_array($videos)
            ? collect($videos)->filter(fn (mixed $video) => is_array($video) && isset($video['url']))->values()->all()
            : [];
    }

    /**
     * Get the billed seconds from the request's `duration` or `num_frames`.
     *
     * @param  array<string, mixed>  $requestData
     */
    protected function durationSeconds(array $requestData): ?float
    {
        $duration = $requestData['duration'] ?? null;

        if (is_string($duration)) {
            $duration = rtrim(trim($duration), 's');
        }

        $seconds = Payload::float($duration);

        if ($seconds > 0) {
            return $seconds;
        }

        $frames = Payload::float($requestData['num_frames'] ?? null);

        return $frames > 0 ? $frames / self::BILLED_FPS : null;
    }
}
