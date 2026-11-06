<?php

declare(strict_types=1);

namespace Spectra\Providers\Google\Handlers;

use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Contracts\ExtractsModelFromRequest;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\ImageMetrics;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Google\ExtractsModelFromGoogleEndpoint;
use Spectra\Providers\Google\GeminiUsage;
use Spectra\Providers\Google\ReadsGeminiParts;
use Spectra\Support\MediaPersister;

/**
 * Gemini image generation (inline image parts of `generateContent`) and
 * Imagen `generateImages` responses.
 */
class ImageHandler implements ExtractsModelFromRequest, ExtractsModelFromResponse, Handler, HasMedia, MatchesResponseShape
{
    use ExtractsModelFromGoogleEndpoint;
    use MatchesParametricEndpoints;
    use ReadsGeminiParts;

    /**
     * Get the model type this handler records.
     */
    public function modelType(): ModelType
    {
        return ModelType::Image;
    }

    /**
     * Get the Gemini and Imagen image generation endpoint paths.
     */
    public function endpoints(): array
    {
        return [
            '/{version}/models/{model}:generateContent',
            '/{version}/models/{model}:streamGenerateContent',
            '/{version}/models/{model}:generateImages',
        ];
    }

    /**
     * Get the token usage, image count and grounding calls of an image response.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $imageCount = collect($this->firstCandidateParts($responseData))
            ->filter(fn (array $part) => $this->isInlinePart($part, 'image/'))
            ->count() + count($this->generatedImages($responseData));

        $usage = GeminiUsage::metadata($responseData);
        $tokens = GeminiUsage::tokens($usage);

        // Image tokens bill at the image output rate. Without a per-modality
        // breakdown, the candidate tokens of a response that produced images
        // are taken as image tokens (thinking tokens stay at the text rate).
        if ($imageCount > 0 && $tokens->imageOutputTokens === 0 && ! isset($usage['candidatesTokensDetails'])) {
            $tokens = new TokenMetrics(
                promptTokens: $tokens->promptTokens,
                completionTokens: $tokens->completionTokens,
                cachedTokens: $tokens->cachedTokens,
                reasoningTokens: $tokens->reasoningTokens,
                audioInputTokens: $tokens->audioInputTokens,
                imageOutputTokens: $tokens->completionTokens - $tokens->reasoningTokens,
            );
        }

        return new Metrics(
            tokens: $tokens,
            image: new ImageMetrics(
                count: $imageCount,
            ),
            toolCalls: GeminiUsage::groundingCalls($responseData),
        );
    }

    /**
     * Get the model from the response's `modelVersion`.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractModelFromResponse(array $response): ?string
    {
        return filled($response['modelVersion'] ?? null) ? $response['modelVersion'] : null;
    }

    /**
     * Get the response text, with a placeholder for each generated image.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        $parts = [];

        foreach ($this->firstCandidateParts($response) as $part) {
            if ($this->isInlinePart($part, 'image/')) {
                $parts[] = '[generated image]';
            } elseif (filled($part['text'] ?? null)) {
                $parts[] = $part['text'];
            }
        }

        foreach ($this->generatedImages($response) as $image) {
            $parts[] = '[generated image]';
        }

        return filled($parts) ? implode("\n", $parts) : null;
    }

    /**
     * Determine if the body holds inline image data or Imagen images.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchesResponse(array $data): bool
    {
        foreach ($this->firstCandidateParts($data) as $part) {
            if ($this->isInlinePart($part, 'image/')) {
                return true;
            }
        }

        return isset($data['generatedImages']);
    }

    /**
     * Store the generated inline and Imagen images and return their paths.
     *
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        $persister = app(MediaPersister::class);
        $stored = [];
        $index = 0;

        foreach ($this->firstCandidateParts($responseData) as $part) {
            $content = $this->isInlinePart($part, 'image/') ? $this->inlineBytes($part) : null;

            if ($content === null) {
                continue;
            }

            $extension = match ($this->inlineMimeType($part)) {
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                default => 'png',
            };

            $stored[] = $persister->store($requestId, $index, $content, $extension);
            $index++;
        }

        foreach ($this->generatedImages($responseData) as $image) {
            $imageBytes = data_get($image, 'image.imageBytes');

            if (filled($imageBytes)) {
                $content = base64_decode($imageBytes, true);

                if ($content === false) {
                    continue;
                }

                $stored[] = $persister->store($requestId, $index, $content, 'png');
                $index++;
            }
        }

        return $stored;
    }

    /**
     * Get the images of an Imagen `generateImages` response.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<mixed>>
     */
    protected function generatedImages(array $data): array
    {
        $images = $data['generatedImages'] ?? null;

        if (! is_array($images)) {
            return [];
        }

        return collect($images)->filter(fn (mixed $image) => is_array($image) && isset($image['image']))->values()->all();
    }
}
