<?php

declare(strict_types=1);

namespace Spectra\Providers\Replicate\Handlers;

use Illuminate\Support\Facades\Http;
use Spectra\Contracts\ExtractsModelFromRequest;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasFinishReason;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\HasProviderLatency;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Contracts\ReportsJobFailure;
use Spectra\Contracts\SkipsResponse;
use Spectra\Data\Metrics;
use Spectra\Data\VideoMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Replicate\Concerns\HandlesPredictions;
use Spectra\Support\MediaPersister;

/**
 * Replicate predictions that produce a video.
 *
 * Video models bill per second of output, so the length is taken from the
 * prediction input: `duration` (Veo, Kling, ...) or `num_frames` divided by
 * `frames_per_second` / `fps` (Wan). Replicate echoes the input in every
 * response, so polls get it too.
 */
class VideoHandler implements ExtractsModelFromRequest, ExtractsModelFromResponse, Handler, HasFinishReason, HasMedia, HasProviderLatency, MatchesResponseShape, ReportsJobFailure, SkipsResponse
{
    use HandlesPredictions;

    /** Seconds to wait for a generated video to download. */
    protected const MEDIA_DOWNLOAD_TIMEOUT_SECONDS = 60;

    /**
     * Get the model type Replicate video predictions are recorded as.
     */
    public function modelType(): ModelType
    {
        return ModelType::Video;
    }

    /**
     * Build the video count and requested length of a succeeded prediction.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $succeeded = ($responseData['status'] ?? null) === 'succeeded' && $this->outputUrls($responseData) !== [];

        return new Metrics(
            video: new VideoMetrics(
                count: $succeeded ? count($this->outputUrls($responseData)) : 0,
                durationSeconds: $succeeded ? $this->durationSeconds($this->predictionInput($requestData, $responseData)) : null,
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

        return $urls !== [] ? implode("\n", $urls) : null;
    }

    /**
     * Determine if the prediction produced a video.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchesResponse(array $data): bool
    {
        return $this->predictionKind($data) === 'video';
    }

    /**
     * Download and store every output video, skipping any that fail.
     *
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        $persister = app(MediaPersister::class);
        $stored = [];

        foreach ($this->outputUrls($responseData) as $i => $url) {
            // One video that fails to download or store must not lose the others.
            $path = rescue(function () use ($persister, $requestId, $i, $url) {
                $response = Http::withoutAITracking()->timeout(self::MEDIA_DOWNLOAD_TIMEOUT_SECONDS)->get($url);

                if (! $response->successful()) {
                    return null;
                }

                $extension = $this->resolveExtensionFromContentType($response->header('Content-Type'));

                return $persister->store($requestId, $i, $response->body(), $extension);
            });

            if ($path !== null) {
                $stored[] = $path;
            }
        }

        return $stored;
    }

    /**
     * Seconds of video the input asked for, or null when it doesn't say
     * (the model then used its own default length).
     *
     * @param  array<string, mixed>  $input
     */
    protected function durationSeconds(array $input): ?float
    {
        $duration = $input['duration'] ?? $input['duration_seconds'] ?? null;

        // Some models take the length as a string such as "8" or "8s".
        if (is_string($duration)) {
            $duration = rtrim(trim($duration), 's');
        }

        if (is_numeric($duration) && (float) $duration > 0) {
            return (float) $duration;
        }

        $frames = $input['num_frames'] ?? null;
        $fps = $input['frames_per_second'] ?? $input['fps'] ?? null;

        if (is_numeric($frames) && is_numeric($fps) && (float) $fps > 0) {
            return (float) $frames / (float) $fps;
        }

        return null;
    }

    /**
     * Get the file extension for a video content type, defaulting to mp4.
     */
    private function resolveExtensionFromContentType(?string $contentType): string
    {
        if (filled($contentType)) {
            $mime = strtolower(trim(explode(';', $contentType, 2)[0]));

            return match ($mime) {
                'video/mp4' => 'mp4',
                'video/webm' => 'webm',
                'video/quicktime' => 'mov',
                'video/x-msvideo' => 'avi',
                default => 'mp4',
            };
        }

        return 'mp4';
    }
}
