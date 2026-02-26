<?php

namespace Spectra\Providers\OpenRouter\Handlers;

use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasFinishReason;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\ImageMetrics;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Support\MediaPersister;

class ImageHandler implements ExtractsModelFromResponse, Handler, HasFinishReason, HasMedia, MatchesResponseShape
{
    use MatchesEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Image;
    }

    public function endpoints(): array
    {
        return ['/api/v1/chat/completions'];
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        return new Metrics(
            tokens: new TokenMetrics(
                promptTokens: (int) ($responseData['usage']['prompt_tokens'] ?? 0),
                completionTokens: (int) ($responseData['usage']['completion_tokens'] ?? 0),
            ),
            image: new ImageMetrics(
                count: $this->countImages($responseData),
            ),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractModelFromResponse(array $response): ?string
    {
        return $response['model'] ?? null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        $parts = [];

        $content = $response['choices'][0]['message']['content'] ?? null;
        if (is_string($content) && $content !== '') {
            $parts[] = $content;
        }

        $imageCount = $this->countImages($response);
        if ($imageCount > 0) {
            $parts[] = "[{$imageCount} generated image(s)]";
        }

        return ! empty($parts) ? implode("\n", $parts) : null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractFinishReason(array $response): ?string
    {
        return $response['choices'][0]['finish_reason'] ?? null;
    }

    /**
     * OpenRouter returns images via chat/completions with images in message.images[].
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

        foreach ($responseData['choices'] ?? [] as $choice) {
            foreach ($choice['message']['images'] ?? [] as $image) {
                $dataUrl = $image['image_url']['url'] ?? null;

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
        $count = 0;

        foreach ($data['choices'] ?? [] as $choice) {
            $count += count($choice['message']['images'] ?? []);
        }

        return $count;
    }
}
