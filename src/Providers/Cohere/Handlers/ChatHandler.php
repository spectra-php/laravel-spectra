<?php

declare(strict_types=1);

namespace Spectra\Providers\Cohere\Handlers;

use Spectra\Concerns\ExtractsModelField;
use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasFinishReason;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Contracts\StreamsResponse;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Cohere\Streaming\ChatStreaming;
use Spectra\Support\Payload;
use Spectra\Support\Tracking\StreamHandler;

/**
 * Cohere v2 Chat (`POST /v2/chat`), streamed or not.
 *
 * Cohere bills `usage.billed_units`, not the raw `usage.tokens` counts (which
 * include tokens Cohere adds itself), so the billed units are recorded.
 */
class ChatHandler implements ExtractsModelFromResponse, Handler, HasFinishReason, MatchesResponseShape, StreamsResponse
{
    use ExtractsModelField;
    use MatchesEndpoints;

    /**
     * Get the model type Cohere chat requests are recorded as.
     */
    public function modelType(): ModelType
    {
        return ModelType::Text;
    }

    /**
     * Get the Cohere v2 chat endpoint path.
     */
    public function endpoints(): array
    {
        return ['/v2/chat'];
    }

    /**
     * Build the billed token usage of a Cohere chat response.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $usage = is_array($responseData['usage'] ?? null) ? $responseData['usage'] : [];

        return new Metrics(tokens: self::tokensFromUsage($usage));
    }

    /**
     * Billed tokens from a v2 `usage` object (also used by the stream's
     * `message-end` event). `cached_tokens` are prompt tokens that hit
     * Cohere's cache; Cohere publishes no separate price for them.
     *
     * @param  array<string, mixed>  $usage
     */
    public static function tokensFromUsage(array $usage): TokenMetrics
    {
        $billed = is_array($usage['billed_units'] ?? null) ? $usage['billed_units'] : [];
        $prompt = Payload::int($billed['input_tokens'] ?? null);

        return new TokenMetrics(
            promptTokens: $prompt,
            completionTokens: Payload::int($billed['output_tokens'] ?? null),
            cachedTokens: min($prompt, Payload::int($usage['cached_tokens'] ?? null)),
        );
    }

    /**
     * Get the joined text blocks of a Cohere chat message.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        if (! isset($response['message']['content']) || ! is_array($response['message']['content'])) {
            return null;
        }

        $texts = [];

        foreach ($response['message']['content'] as $block) {
            if (($block['type'] ?? null) === 'text' && filled($block['text'] ?? null)) {
                $texts[] = $block['text'];
            }
        }

        return filled($texts) ? implode("\n", $texts) : null;
    }

    /**
     * Get the finish reason of a Cohere chat response.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractFinishReason(array $response): ?string
    {
        return $response['finish_reason'] ?? null;
    }

    /**
     * Determine if the body is a Cohere chat response with a message and finish reason.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchesResponse(array $data): bool
    {
        return isset($data['message'], $data['finish_reason']);
    }

    /**
     * Create the stream handler for Cohere chat streams.
     */
    public function streamingHandler(): StreamHandler
    {
        return new ChatStreaming;
    }
}
