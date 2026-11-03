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
use Spectra\Data\ImageMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Replicate\Concerns\HandlesPredictions;
use Spectra\Support\MediaPersister;

/**
 * Replicate predictions that produce images.
 *
 * Image models return one URL (FLUX 1.1 Pro, Ideogram, Recraft) or a list of
 * URLs. The count comes from `metrics.image_count` when Replicate reports
 * it, otherwise from the image URLs in the output.
 */
class ImageHandler implements ExtractsModelFromRequest, ExtractsModelFromResponse, Handler, HasFinishReason, HasMedia, HasProviderLatency, MatchesResponseShape, ReportsJobFailure, SkipsResponse
{
    use HandlesPredictions;

    /** Seconds to wait for a generated image to download. */
    protected const MEDIA_DOWNLOAD_TIMEOUT_SECONDS = 30;

    public function modelType(): ModelType
    {
        return ModelType::Image;
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $reported = $responseData['metrics']['image_count'] ?? null;

        return new Metrics(
            image: new ImageMetrics(
                count: is_numeric($reported) ? (int) $reported : count($this->imageUrls($responseData)),
            ),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        $urls = $this->outputUrls($response);

        return $urls !== [] ? implode("\n", $urls) : null;
    }

    /** @param  array<string, mixed>  $data */
    public function matchesResponse(array $data): bool
    {
        return $this->predictionKind($data) === 'image';
    }

    /**
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        $persister = app(MediaPersister::class);
        $stored = [];

        foreach ($this->imageUrls($responseData) as $i => $url) {
            // One image that fails to download or store must not lose the others.
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
     * Output URLs that are not clearly another kind of file (audio, video).
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    protected function imageUrls(array $data): array
    {
        return array_values(array_filter(
            $this->outputUrls($data),
            fn (string $url) => ! in_array($this->fileKind($url), ['video', 'audio'], true),
        ));
    }

    private function resolveExtensionFromContentType(?string $contentType): string
    {
        if (filled($contentType)) {
            $mime = strtolower(trim(explode(';', $contentType, 2)[0]));

            return match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                default => 'png',
            };
        }

        return 'png';
    }
}
