<?php

namespace Spectra\Providers\Anthropic\Handlers;

use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasFinishReason;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Contracts\StreamsResponse;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Anthropic\Streaming\MessageStreaming;
use Spectra\Support\Tracking\StreamHandler;

class MessageHandler implements ExtractsModelFromResponse, Handler, HasFinishReason, MatchesResponseShape, StreamsResponse
{
    use MatchesEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Text;
    }

    public function endpoints(): array
    {
        return ['/v1/messages'];
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $usage = $responseData['usage'] ?? [];

        return new Metrics(
            tokens: new TokenMetrics(
                promptTokens: (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0),
                completionTokens: (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0),
                cachedTokens: (int) ($usage['cache_read_input_tokens'] ?? 0),
                cacheCreationTokens: (int) ($usage['cache_creation_input_tokens'] ?? 0),
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
        if (! isset($response['content'])) {
            return null;
        }

        // Flat string content (simplified format)
        if (is_string($response['content'])) {
            return $response['content'] !== '' ? $response['content'] : null;
        }

        // Standard API content blocks array
        if (! is_array($response['content'])) {
            return null;
        }

        $texts = [];

        foreach ($response['content'] as $block) {
            if (isset($block['text']) && $block['text'] !== '') {
                $texts[] = $block['text'];
            }
        }

        if (empty($texts)) {
            return null;
        }

        return count($texts) === 1 ? $texts[0] : implode("\n", $texts);
    }

    /** @param  array<string, mixed>  $response */
    public function extractFinishReason(array $response): ?string
    {
        return $response['stop_reason'] ?? null;
    }

    /** @param  array<string, mixed>  $data */
    public function matchesResponse(array $data): bool
    {
        return ($data['type'] ?? null) === 'message'
            || (isset($data['content']) && isset($data['stop_reason']));
    }

    public function streamingHandler(): StreamHandler
    {
        return new MessageStreaming;
    }
}
