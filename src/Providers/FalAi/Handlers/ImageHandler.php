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
use Spectra\Data\ImageMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\FalAi\Concerns\HandlesFalRequests;
use Spectra\Support\MediaPersister;

/**
 * fal.ai image models, called directly or through the queue.
 *
 * Besides the image count it reports the output size in megapixels for
 * models billed per megapixel (FLUX dev, schnell, 1.1 pro). fal rounds each
 * image up to whole megapixels of 1024 x 1024 pixels: its own examples bill
 * a 1024x1024 image as 1 MP, 1920x1080 as 2 MP and 512x512 as 1 MP.
 */
class ImageHandler implements ExtractsModelFromRequest, Handler, HasFinishReason, HasMedia, MatchesResponseShape, SkipsResponse
{
    use HandlesFalRequests;

    /** Pixels in one billed megapixel. */
    protected const MEGAPIXEL = 1024 * 1024;

    /** Seconds to wait for a generated image to download. */
    protected const MEDIA_DOWNLOAD_TIMEOUT_SECONDS = 30;

    /**
     * Get the model type fal.ai image requests are recorded as.
     */
    public function modelType(): ModelType
    {
        return ModelType::Image;
    }

    /**
     * Build the image count and billed megapixels of a fal.ai result.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $images = $this->resolveImages($responseData);

        return new Metrics(
            image: new ImageMetrics(
                count: count($images),
                megapixels: $this->megapixels($images),
            ),
        );
    }

    /**
     * Get the URLs of the generated images, one per line.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        $urls = collect($this->resolveImages($response))
            ->map(fn (array $image) => is_string($image['url'] ?? null) ? $image['url'] : null)
            ->filter()
            ->all();

        return filled($urls) ? implode("\n", $urls) : null;
    }

    /**
     * Determine if the fal.ai result holds images.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchesResponse(array $data): bool
    {
        return $this->resolveImages($data) !== [];
    }

    /**
     * Download and store every generated image, skipping any that fail.
     *
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        $persister = app(MediaPersister::class);
        $stored = [];

        foreach ($this->resolveImages($responseData) as $i => $image) {
            $url = $image['url'] ?? null;

            if (! is_string($url)) {
                continue;
            }

            // One image that fails to download or store must not lose the others.
            $path = rescue(function () use ($persister, $requestId, $i, $url, $image) {
                $response = Http::withoutAITracking()->timeout(self::MEDIA_DOWNLOAD_TIMEOUT_SECONDS)->get($url);

                if (! $response->successful()) {
                    return null;
                }

                $extension = $this->resolveExtension($image['content_type'] ?? $response->header('Content-Type'));

                return $persister->store($requestId, $i, $response->body(), $extension);
            });

            if ($path !== null) {
                $stored[] = $path;
            }
        }

        return $stored;
    }

    /**
     * Images from a result (`images[]`, or a single `image` for edit
     * models), unwrapping webhook `payload`s.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<mixed>>
     */
    protected function resolveImages(array $data): array
    {
        $output = $this->output($data);
        $images = $output['images'] ?? (is_array($output['image'] ?? null) ? [$output['image']] : []);

        return is_array($images) ? collect($images)->filter(fn (mixed $image) => is_array($image))->values()->all() : [];
    }

    /**
     * Billed megapixels, or null when an image has no size.
     *
     * @param  array<int, array<mixed>>  $images
     */
    protected function megapixels(array $images): ?float
    {
        if ($images === []) {
            return null;
        }

        $total = 0;

        foreach ($images as $image) {
            $width = $image['width'] ?? null;
            $height = $image['height'] ?? null;

            if (! is_numeric($width) || ! is_numeric($height)) {
                return null;
            }

            $total += max(1, (int) ceil(((float) $width * (float) $height) / self::MEGAPIXEL));
        }

        return (float) $total;
    }

    /**
     * Get the file extension for an image content type, defaulting to png.
     */
    private function resolveExtension(?string $contentType): string
    {
        if (filled($contentType)) {
            $mime = strtolower(trim(explode(';', $contentType, 2)[0]));

            return match ($mime) {
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                default => 'png',
            };
        }

        return 'png';
    }
}
