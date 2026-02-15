<?php

namespace Spectra\Providers\OpenAI;

use Illuminate\Support\Facades\Http;
use Spectra\Support\MediaPersister;

trait StoresOpenAiImageMedia
{
    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        if (! isset($response['data']) || ! is_array($response['data'])) {
            return null;
        }

        $urls = [];
        foreach ($response['data'] as $item) {
            if (isset($item['url'])) {
                $urls[] = $item['url'];
            } elseif (isset($item['b64_json'])) {
                $urls[] = '[base64 image data]';
            }
        }

        return ! empty($urls) ? implode("\n", $urls) : null;
    }

    /**
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        $persister = app(MediaPersister::class);
        $stored = [];

        foreach ($responseData['data'] ?? [] as $i => $item) {
            if (isset($item['b64_json'])) {
                $content = base64_decode($item['b64_json'], true);

                if (! is_string($content) || $content === '') {
                    continue;
                }

                $stored[] = $persister->store($requestId, $i, $content, 'png');
            } elseif (isset($item['url'])) {
                if (! is_string($item['url'])) {
                    continue;
                }

                $response = Http::withoutAITracking()->get($item['url']);
                if (! $response->successful()) {
                    continue;
                }

                $stored[] = $persister->store(
                    $requestId,
                    $i,
                    $response->body(),
                    $this->resolveImageExtension($item['url'], $response->header('Content-Type')),
                );
            }
        }

        return $stored;
    }

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
        $extension = strtolower((string) pathinfo(is_string($path) ? $path : '', PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'avif', 'heic', 'heif'];

        if (in_array($extension, $allowed, true)) {
            return $extension === 'jpeg' ? 'jpg' : $extension;
        }

        return 'png';
    }

    private function normalizeContentType(?string $contentType): ?string
    {
        if (! is_string($contentType) || $contentType === '') {
            return null;
        }

        $parts = explode(';', $contentType, 2);

        return strtolower(trim($parts[0]));
    }
}
