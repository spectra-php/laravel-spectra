<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenRouter\Handlers;

use Spectra\Contracts\HasMedia;
use Spectra\Data\ImageMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Support\MediaPersister;

/**
 * Images generated through OpenRouter `chat/completions` (Gemini Flash Image
 * and other models with image output), returned in `message.images[]`.
 *
 * Shares the chat endpoint, so it only claims responses that carry images.
 * Usage and the reported cost are read exactly like a chat response.
 */
class ImageHandler extends ChatHandler implements HasMedia
{
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
        $metrics = parent::extractMetrics($requestData, $responseData);

        return new Metrics(
            tokens: $metrics->tokens,
            image: new ImageMetrics(count: $this->countImages($responseData)),
            toolCalls: $metrics->toolCalls,
            reportedCostInCents: $metrics->reportedCostInCents,
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        $parts = [];

        $content = data_get($response, 'choices.0.message.content');
        if (filled($content)) {
            $parts[] = $content;
        }

        $imageCount = $this->countImages($response);
        if ($imageCount > 0) {
            $parts[] = "[{$imageCount} generated image(s)]";
        }

        return ! empty($parts) ? implode("\n", $parts) : null;
    }

    /**
     * Disambiguate from ChatHandler by detecting the images array.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchesResponse(array $data): bool
    {
        return isset($data['choices'][0]['message']['images'])
            && is_array($data['choices'][0]['message']['images'])
            && ! empty($data['choices'][0]['message']['images']);
    }

    /**
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        $persister = app(MediaPersister::class);
        $stored = [];
        $index = 0;

        $choices = $responseData['choices'] ?? null;

        foreach (is_array($choices) ? $choices : [] as $choice) {
            $images = data_get($choice, 'message.images');

            foreach (is_array($images) ? $images : [] as $image) {
                $dataUrl = data_get($image, 'image_url.url');

                if (! is_string($dataUrl)) {
                    continue;
                }

                // Parse data URL: data:image/png;base64,iVBOR...
                if (preg_match('#^data:image/([a-z]+);base64,(.+)$#i', $dataUrl, $matches)) {
                    $extension = strtolower($matches[1]) === 'jpeg' ? 'jpg' : strtolower($matches[1]);
                    $content = base64_decode($matches[2], true);

                    if ($content === false) {
                        continue;
                    }

                    $stored[] = $persister->store($requestId, $index, $content, $extension);
                    $index++;
                }
            }
        }

        return $stored;
    }

    /** @param  array<string, mixed>  $data */
    private function countImages(array $data): int
    {
        $choices = $data['choices'] ?? null;

        return collect(is_array($choices) ? $choices : [])
            ->map(fn (mixed $choice) => data_get($choice, 'message.images'))
            ->sum(fn (mixed $images) => is_array($images) ? count($images) : 0);
    }
}
