<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenRouter\Handlers;

use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Providers\Concerns\OpenAiCompatibleChatHandler;
use Spectra\Providers\OpenRouter\Concerns\ReadsReportedCost;
use Spectra\Support\Payload;

/**
 * OpenRouter `chat/completions`, streamed or not.
 *
 * The cost comes from `usage.cost` (plus the upstream cost for BYOK), so it
 * is exact for every routed model, routing suffix and `openrouter/auto`. The
 * response `model` is the model OpenRouter actually served; it is kept as
 * the snapshot and priced first when the catalog has to be used.
 */
class ChatHandler extends OpenAiCompatibleChatHandler
{
    use ReadsReportedCost;

    /**
     * Get the chat completion endpoint paths.
     */
    public function endpoints(): array
    {
        return ['/api/v1/chat/completions'];
    }

    /**
     * Get the chat metrics, with the web searches OpenRouter ran counted as tool calls.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $metrics = parent::extractMetrics($requestData, $responseData);
        $usage = $this->usageFrom($responseData) ?? [];

        // Web searches run by OpenRouter (the `:online` suffix, the web
        // plugin, or the model's native search). Their price is already inside
        // `usage.cost`; they are counted so the request shows it used a
        // server tool. Chat responses report them under
        // `server_tool_use_details`, image responses under `server_tool_use`.
        $tools = $usage['server_tool_use_details'] ?? $usage['server_tool_use'] ?? [];
        $searches = is_array($tools) ? Payload::int($tools['web_search_requests'] ?? null) : 0;

        if ($searches <= 0) {
            return $metrics;
        }

        return new Metrics(
            tokens: $metrics->tokens,
            toolCalls: ['web_search' => $searches],
            reportedCostInCents: $metrics->reportedCostInCents,
        );
    }

    /**
     * Adds image output tokens (`completion_tokens_details.image_tokens`),
     * which image models such as Gemini Flash Image bill at a higher rate,
     * and the 1-hour share of Anthropic cache writes (`cache_creation`).
     *
     * @param  array<string, mixed>  $usage
     */
    public function tokensFromUsage(array $usage): TokenMetrics
    {
        $tokens = parent::tokensFromUsage($usage);
        $imageOutput = Payload::int($usage['completion_tokens_details']['image_tokens'] ?? null);
        $writes1h = Payload::int($usage['cache_creation']['ephemeral_1h_input_tokens'] ?? null);

        if ($imageOutput <= 0 && $writes1h <= 0) {
            return $tokens;
        }

        return new TokenMetrics(
            promptTokens: $tokens->promptTokens,
            completionTokens: $tokens->completionTokens,
            cachedTokens: $tokens->cachedTokens,
            reasoningTokens: $tokens->reasoningTokens,
            cacheWriteTokens: $tokens->cacheWriteTokens,
            cacheWrite1hTokens: min($writes1h, $tokens->cacheWriteTokens),
            audioInputTokens: $tokens->audioInputTokens,
            imageInputTokens: $tokens->imageInputTokens,
            audioOutputTokens: $tokens->audioOutputTokens,
            imageOutputTokens: $imageOutput,
        );
    }

    /**
     * Get the cost OpenRouter reports in `usage.cost`, in US cents.
     *
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $responseData
     */
    public function reportedCostInCents(array $usage, array $responseData): ?float
    {
        return $this->costFromUsage($usage);
    }

    /**
     * OpenRouter responses don't always carry an `object` discriminator, so we
     * match on the presence of the OpenAI-shaped `choices` + `usage` keys.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchesResponse(array $data): bool
    {
        return isset($data['choices'], $data['usage']);
    }
}
