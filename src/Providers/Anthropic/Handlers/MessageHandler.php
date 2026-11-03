<?php

declare(strict_types=1);

namespace Spectra\Providers\Anthropic\Handlers;

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
use Spectra\Providers\Anthropic\Streaming\MessageStreaming;
use Spectra\Support\Tracking\StreamHandler;

/**
 * The Messages API (`POST /v1/messages`), streamed or not.
 */
class MessageHandler implements ExtractsModelFromResponse, Handler, HasFinishReason, MatchesResponseShape, StreamsResponse
{
    use ExtractsModelField;
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
     * Anthropic's `input_tokens` counts only the uncached part of the prompt;
     * cache reads and cache writes are reported beside it. They are added back
     * so promptTokens is the full prompt, as TokenMetrics requires.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $usage = is_array($responseData['usage'] ?? null) ? $responseData['usage'] : [];

        return new Metrics(
            tokens: self::tokensFromUsage($usage),
            toolCalls: self::serverToolCalls($usage),
        );
    }

    /**
     * Normalise a Messages API usage object (also a streamed one).
     *
     * Thinking tokens are already inside `output_tokens`; the API doesn't
     * report them separately.
     *
     * @param  array<string, mixed>  $usage
     */
    public static function tokensFromUsage(array $usage): TokenMetrics
    {
        $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? 0);
        $cacheWrite = (int) ($usage['cache_creation_input_tokens'] ?? 0);
        $cacheWrite1h = (int) ($usage['cache_creation']['ephemeral_1h_input_tokens'] ?? 0);

        // Already-normalised usage (e.g. from manual tracking) carries prompt_tokens as the total.
        $prompt = isset($usage['input_tokens'])
            ? (int) $usage['input_tokens'] + $cacheRead + $cacheWrite
            : (int) ($usage['prompt_tokens'] ?? 0);

        $completion = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);

        // With server-side compaction the top-level counts leave out the
        // compaction step, which is billed too: add those iterations back.
        foreach (self::compactionIterations($usage) as $iteration) {
            $iterationRead = (int) ($iteration['cache_read_input_tokens'] ?? 0);
            $iterationWrite = (int) ($iteration['cache_creation_input_tokens'] ?? 0);

            $prompt += (int) ($iteration['input_tokens'] ?? 0) + $iterationRead + $iterationWrite;
            $completion += (int) ($iteration['output_tokens'] ?? 0);
            $cacheRead += $iterationRead;
            $cacheWrite += $iterationWrite;
            $cacheWrite1h += (int) ($iteration['cache_creation']['ephemeral_1h_input_tokens'] ?? 0);
        }

        return new TokenMetrics(
            promptTokens: $prompt,
            completionTokens: $completion,
            cachedTokens: $cacheRead,
            cacheWriteTokens: $cacheWrite,
            cacheWrite1hTokens: min($cacheWrite1h, $cacheWrite),
        );
    }

    /**
     * Server tool usage, keyed like AnthropicPricing::toolCallPricing().
     *
     * Web search is billed per search. Web fetch is free and code execution
     * is billed per container-hour, which a response can't tell us; both are
     * still counted so the dashboard shows the tool use.
     *
     * @param  array<string, mixed>  $usage
     * @return array<string, int>
     */
    public static function serverToolCalls(array $usage): array
    {
        $serverToolUse = is_array($usage['server_tool_use'] ?? null) ? $usage['server_tool_use'] : [];

        return array_filter([
            'web_search_requests' => (int) ($serverToolUse['web_search_requests'] ?? 0),
            'web_fetch_requests' => (int) ($serverToolUse['web_fetch_requests'] ?? 0),
            'code_execution_requests' => (int) ($serverToolUse['code_execution_requests'] ?? 0),
        ]);
    }

    /**
     * @param  array<string, mixed>  $usage
     * @return list<array<string, mixed>>
     */
    protected static function compactionIterations(array $usage): array
    {
        if (! is_array($usage['iterations'] ?? null)) {
            return [];
        }

        return array_values(array_filter(
            $usage['iterations'],
            fn ($iteration) => is_array($iteration) && ($iteration['type'] ?? null) === 'compaction',
        ));
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        if (! isset($response['content'])) {
            return null;
        }

        // Flat string content (simplified format)
        if (is_string($response['content'])) {
            return filled($response['content']) ? $response['content'] : null;
        }

        // Standard API content blocks array
        if (! is_array($response['content'])) {
            return null;
        }

        $texts = [];

        foreach ($response['content'] as $block) {
            if (filled($block['text'] ?? null)) {
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
