<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenRouter\Handlers;

use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\StreamsResponse;
use Spectra\Data\ImageMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Concerns\OpenAiCompatibleStreaming;
use Spectra\Support\MediaPersister;
use Spectra\Support\Tracking\StreamHandler;

/**
 * OpenRouter's image API, `POST /api/v1/images`.
 *
 * The response has no model or id, only `data[].b64_json` and a `usage`
 * object with the reported cost, so the model comes from the request. A
 * streamed request (`stream: true`) ends with an `image_generation.completed`
 * event that carries the same usage; streamed images are not counted.
 */
class ImageGenerationHandler implements Handler, HasMedia, StreamsResponse
{
    use MatchesEndpoints;

    public function __construct(
        protected ChatHandler $chat,
    ) {}

    public function modelType(): ModelType
    {
        return ModelType::Image;
    }

    public function endpoints(): array
    {
        return ['/api/v1/images'];
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $usage = $this->chat->usageFrom($responseData);

        return new Metrics(
            tokens: $usage !== null ? $this->chat->tokensFromUsage($usage) : null,
            image: new ImageMetrics(count: count($this->images($responseData))),
            reportedCostInCents: $usage !== null ? $this->chat->reportedCostInCents($usage, $responseData) : null,
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        $count = count($this->images($response));

        return $count > 0 ? "[{$count} generated image(s)]" : null;
    }

    public function streamingHandler(): StreamHandler
    {
        return new OpenAiCompatibleStreaming($this->chat);
    }

    /**
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        $persister = app(MediaPersister::class);
        $stored = [];

        foreach ($this->images($responseData) as $index => $image) {
            $content = is_string($image['b64_json'] ?? null) ? base64_decode($image['b64_json'], true) : false;

            if ($content === false || blank($content)) {
                continue;
            }

            $stored[] = $persister->store($requestId, $index, $content, $this->extension($image['media_type'] ?? null));
        }

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    protected function images(array $data): array
    {
        $images = $data['data'] ?? [];

        return is_array($images) ? array_values(array_filter($images, 'is_array')) : [];
    }

    protected function extension(mixed $mediaType): string
    {
        return match (is_string($mediaType) ? strtolower($mediaType) : null) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/gif' => 'gif',
            default => 'png',
        };
    }
}
