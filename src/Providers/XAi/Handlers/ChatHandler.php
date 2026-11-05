<?php

declare(strict_types=1);

namespace Spectra\Providers\XAi\Handlers;

use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Contracts\SkipsResponse;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Providers\Concerns\OpenAiCompatibleChatHandler;
use Spectra\Providers\XAi\Streaming\ChatStreaming;
use Spectra\Providers\XAi\XAiUsage;
use Spectra\Support\Payload;
use Spectra\Support\Tracking\StreamHandler;

/**
 * xAI Chat Completions, including deferred completions.
 *
 * xAI differs from OpenAI in three ways that matter for cost:
 * - `completion_tokens` does not include `reasoning_tokens`; both are billed
 *   (the docs example reports 32 prompt + 9 completion + 94 reasoning = 135).
 * - `usage.cost_in_usd_ticks` is the exact charge, tool fees and long-context
 *   rates included, so it replaces the catalog estimate.
 * - Server-side tools are counted in `usage.server_side_tool_usage_details`.
 *
 * A request with `deferred: true` only returns a `request_id`; the result is
 * fetched later from `/v1/chat/deferred-completion/{request_id}`, which is
 * what gets recorded.
 */
class ChatHandler extends OpenAiCompatibleChatHandler implements SkipsResponse
{
    use MatchesParametricEndpoints;

    /**
     * Get the xAI chat completions and deferred completion endpoint paths.
     */
    public function endpoints(): array
    {
        return [
            '/v1/chat/completions',
            '/v1/chat/deferred-completion/{request_id}',
        ];
    }

    /**
     * Build the token usage, server-side tool calls and reported cost of an xAI completion.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $metrics = parent::extractMetrics($requestData, $responseData);
        $usage = $this->usageFrom($responseData);

        return new Metrics(
            tokens: $metrics->tokens,
            toolCalls: $usage !== null ? XAiUsage::toolCalls($usage) : [],
            reportedCostInCents: $metrics->reportedCostInCents,
        );
    }

    /**
     * Build the token usage of an xAI completion, adding its image input tokens.
     *
     * @param  array<string, mixed>  $usage
     */
    public function tokensFromUsage(array $usage): TokenMetrics
    {
        $tokens = parent::tokensFromUsage($usage);
        $promptDetails = is_array($usage['prompt_tokens_details'] ?? null) ? $usage['prompt_tokens_details'] : [];

        return new TokenMetrics(
            promptTokens: $tokens->promptTokens,
            completionTokens: $tokens->completionTokens,
            cachedTokens: $tokens->cachedTokens,
            reasoningTokens: $tokens->reasoningTokens,
            cacheWriteTokens: $tokens->cacheWriteTokens,
            audioInputTokens: $tokens->audioInputTokens,
            imageInputTokens: Payload::int($promptDetails['image_tokens'] ?? null),
            audioOutputTokens: $tokens->audioOutputTokens,
        );
    }

    /**
     * Determine if reasoning tokens are billed on top of completion tokens, which xAI does.
     */
    protected function reasoningTokensAreSeparate(): bool
    {
        return true;
    }

    /**
     * Get the exact charge xAI reports in `cost_in_usd_ticks`, in cents.
     *
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $responseData
     */
    public function reportedCostInCents(array $usage, array $responseData): ?float
    {
        return XAiUsage::costInCents($usage);
    }

    /**
     * Skip the `{request_id}` acknowledgement of a deferred request and the
     * empty 202 a still-pending deferred completion returns.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function shouldSkipResponse(array $responseData): bool
    {
        return ! isset($responseData['choices'])
            && (blank($responseData) || isset($responseData['request_id']));
    }

    /**
     * Create the stream handler for xAI chat completion streams.
     */
    public function streamingHandler(): StreamHandler
    {
        return new ChatStreaming($this);
    }
}
