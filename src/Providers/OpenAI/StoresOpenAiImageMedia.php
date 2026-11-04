<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenAI;

use Illuminate\Support\Facades\Http;
use Spectra\Support\MediaPersister;

/**
 * Summarises and stores the images of an Images API response (`data[]`
 * with `b64_json` or a temporary `url`).
 */
trait StoresOpenAiImageMedia
{
    /** Seconds to wait for a generated image to download. */
    protected const MEDIA_DOWNLOAD_TIMEOUT_SECONDS = 30;

    /**
     * Get the image URLs of the response, with a placeholder for base64 images.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        if (! isset($response['data']) || ! is_array($response['data'])) {
            return null;
        }

        $urls = [];
        foreach ($response['data'] as $item) {
            $url = data_get($item, 'url');

            if (filled($url)) {
                $urls[] = $url;
            } elseif (data_get($item, 'b64_json') !== null) {
                $urls[] = '[base64 image data]';
            }
        }

        return filled($urls) ? implode("\n", $urls) : null;
    }

    /**
     * Store the base64 or downloaded images and return their paths.
     *
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        $persister = app(MediaPersister::class);
        $stored = [];

        $items = $responseData['data'] ?? null;

        foreach (is_array($items) ? $items : [] as $i => $item) {
            $b64 = data_get($item, 'b64_json');
            $url = data_get($item, 'url');

            if (filled($b64)) {
                $content = base64_decode($b64, true);

                if ($content === false || blank($content)) {
                    continue;
                }

                $stored[] = $persister->store($requestId, $i, $content, 'png');

                continue;
            }

            if (blank($url)) {
                continue;
            }

            // One image that fails to download or store must not lose the others.
            $path = rescue(function () use ($persister, $requestId, $i, $url) {
                $response = Http::withoutAITracking()->timeout(self::MEDIA_DOWNLOAD_TIMEOUT_SECONDS)->get($url);

                if (! $response->successful()) {
                    return null;
                }

                return $persister->store(
                    $requestId,
                    $i,
                    $response->body(),
                    $this->resolveImageExtension($url, $response->header('Content-Type')),
                );
            });

            if ($path !== null) {
                $stored[] = $path;
            }
        }

        return $stored;
    }

    /**
     * Resolve an image's file extension from its content type, then its URL, defaulting to png.
     */
    private function resolveImageExtension(string $url, ?string $contentType): string
    {
        $mime = $this->normalizeContentType($contentType);

        $fromMime = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/bmp' => 'bmp',
            'image/avif' => 'avif',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            default => null,
        };

        if ($fromMime !== null) {
            return $fromMime;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo(is_string($path) ? $path : '', PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'avif', 'heic', 'heif'];

        if (in_array($extension, $allowed, true)) {
            return $extension === 'jpeg' ? 'jpg' : $extension;
        }

        return 'png';
    }

    /**
     * Get the lower-cased mime type of a Content-Type header, without parameters.
     */
    private function normalizeContentType(?string $contentType): ?string
    {
        if (blank($contentType)) {
            return null;
        }

        $parts = explode(';', $contentType, 2);

        return strtolower(trim($parts[0]));
    }
}
