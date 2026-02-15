<?php

namespace Spectra\Providers\OpenAI\Handlers;

use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasFinishReason;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\ImageMetrics;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Support\MediaPersister;

class ResponsesImageHandler implements Handler, HasFinishReason, HasMedia, MatchesResponseShape
{
    use MatchesEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Image;
    }

    public function endpoints(): array
    {
        return ['/v1/responses'];
    }

    /** @param  array<string, mixed>  $data */
    public function matchesResponse(array $data): bool
    {
        if (($data['object'] ?? null) !== 'response') {
            return false;
        }

        return $this->hasImageGenerationOutput($data);
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        return new Metrics(
            tokens: new TokenMetrics(
                promptTokens: (int) ($responseData['usage']['input_tokens'] ?? 0),
                completionTokens: (int) ($responseData['usage']['output_tokens'] ?? 0),
                cachedTokens: (int) ($responseData['usage']['prompt_tokens_details']['cached_tokens'] ?? $responseData['usage']['input_tokens_details']['cached_tokens'] ?? 0),
            ),
            image: new ImageMetrics(
                count: $this->countImageGenerationCalls($responseData),
            ),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractModel(array $response): ?string
    {
        return $response['model'] ?? null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        $parts = [];

        foreach ($response['output'] ?? [] as $outputItem) {
            if (($outputItem['type'] ?? null) === 'image_generation_call') {
                $revisedPrompt = $outputItem['result'] ?? null;

                if (is_string($revisedPrompt)) {
                    $parts[] = '[base64 image data]';
                } elseif (isset($outputItem['revised_prompt'])) {
                    $parts[] = $outputItem['revised_prompt'];
                } else {
                    $parts[] = '[base64 image data]';
                }
            }

            if (($outputItem['type'] ?? null) === 'message' && isset($outputItem['content'])) {
                foreach ($outputItem['content'] as $contentItem) {
                    if (isset($contentItem['text']) && $contentItem['text'] !== '') {
                        $parts[] = $contentItem['text'];
                    }
                }
            }
        }

        return ! empty($parts) ? implode("\n", $parts) : null;
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
        $stored = [];
        $index = 0;

        foreach ($responseData['output'] ?? [] as $outputItem) {
            if (($outputItem['type'] ?? null) !== 'image_generation_call') {
                continue;
            }

            $b64 = $outputItem['result'] ?? null;

            if (is_string($b64)) {
                $content = base64_decode($b64, true);

                if (! is_string($content) || $content === '') {
                    continue;
                }

                $stored[] = $persister->store($requestId, $index, $content, 'png');
                $index++;
            }
        }

        return $stored;
    }

    /** @param  array<string, mixed>  $data */
    private function hasImageGenerationOutput(array $data): bool
    {
        foreach ($data['output'] ?? [] as $item) {
            if (($item['type'] ?? null) === 'image_generation_call') {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $data */
    private function countImageGenerationCalls(array $data): int
    {
        $count = 0;

        foreach ($data['output'] ?? [] as $item) {
            if (($item['type'] ?? null) === 'image_generation_call') {
                $count++;
            }
        }

        return $count;
    }
}
