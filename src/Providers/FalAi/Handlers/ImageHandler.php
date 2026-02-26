<?php

namespace Spectra\Providers\FalAi\Handlers;

use Illuminate\Support\Facades\Http;
use Spectra\Contracts\ExtractsModelFromRequest;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasFinishReason;
use Spectra\Contracts\HasMedia;
use Spectra\Data\ImageMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Support\MediaPersister;

class ImageHandler implements ExtractsModelFromRequest, Handler, HasFinishReason, HasMedia
{
    public function modelType(): ModelType
    {
        return ModelType::Image;
    }

    public function endpoints(): array
    {
        return [
            '/{owner}/{model}',
            '/{owner}/{model}/{variant}',
            '/{owner}/{model}/{version}/{variant}',
        ];
    }

    public function matchesEndpoint(string $endpoint): bool
    {
        // FalAi endpoints are dynamic path-based model identifiers
        // with 2-4 segments (e.g. /fal-ai/fast-sdxl, /fal-ai/flux/dev, /fal-ai/recraft/v3/text-to-image)
        return (bool) preg_match('#^/[^/]+/[^/]+(/[^/]+){0,2}$#', $endpoint);
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $images = $this->resolveImages($responseData);

        return new Metrics(
            image: new ImageMetrics(
                count: count($images),
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $requestData
     */
    public function extractModelFromRequest(array $requestData, string $endpoint): ?string
    {
        return ltrim($endpoint, '/') ?: null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        $images = $this->resolveImages($response);

        if (empty($images)) {
            return null;
        }

        $urls = array_filter(array_map(
            fn (array $image) => $image['url'] ?? null,
            $images,
        ));

        return ! empty($urls) ? implode("\n", $urls) : null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractFinishReason(array $response): ?string
    {
        return $response['status'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        $persister = app(MediaPersister::class);
        $images = $this->resolveImages($responseData);
        $stored = [];

        foreach ($images as $i => $image) {
            $url = $image['url'] ?? null;

            if (! is_string($url)) {
                continue;
            }

            $response = Http::withoutAITracking()->get($url);

            if (! $response->successful()) {
                continue;
            }

            $extension = $this->resolveExtension($image['content_type'] ?? $response->header('Content-Type'));
            $stored[] = $persister->store($requestId, $i, $response->body(), $extension);
        }

        return $stored;
    }

    /**
     * Resolve images from either sync or queue response format.
     *
     * Sync: { "images": [...] }
     * Queue: { "payload": { "images": [...] } }
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function resolveImages(array $data): array
    {
        $images = $data['images']
            ?? $data['payload']['images']
            ?? [];

        return is_array($images) ? $images : [];
    }

    private function resolveExtension(?string $contentType): string
    {
        if (is_string($contentType) && $contentType !== '') {
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
