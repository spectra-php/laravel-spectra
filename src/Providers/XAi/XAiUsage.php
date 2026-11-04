<?php

declare(strict_types=1);

namespace Spectra\Providers\XAi;

use Spectra\Data\TokenMetrics;
use Spectra\Support\Payload;

/**
 * Reads the parts of an xAI `usage` object that every xAI endpoint shares.
 *
 * Chat, Responses, image and video responses all report the exact charge as
 * `cost_in_usd_ticks` (10^10 ticks per US dollar) and, for agentic requests,
 * a `server_side_tool_usage_details` breakdown of the server-side tools used.
 */
final class XAiUsage
{
    /** Ticks in one US cent (10^10 ticks per dollar). */
    public const TICKS_PER_CENT = 100_000_000;

    /**
     * `server_side_tool_usage_details` field => tool pricing key
     * (see XAiPricing::toolCallPricing()).
     *
     * `document_search_calls` counts the `attachment_search` tool (files
     * attached to the message); `file_search_calls` counts collections search.
     * X Search is billed per fetched post and profile, not per call.
     */
    protected const TOOL_KEYS = [
        'web_search_calls' => 'web_search_call',
        'x_search_calls' => 'x_search_call',
        'x_posts_fetched' => 'x_search_post',
        'x_users_fetched' => 'x_search_profile',
        'code_interpreter_calls' => 'code_interpreter_call',
        'file_search_calls' => 'file_search_call',
        'document_search_calls' => 'attachment_search_call',
        'mcp_calls' => 'mcp_call',
        'image_generation_calls' => 'image_generation_call',
    ];

    /**
     * The charge xAI reported, in US cents.
     *
     * @param  array<string, mixed>  $usage
     */
    public static function costInCents(array $usage): ?float
    {
        $ticks = $usage['cost_in_usd_ticks'] ?? null;

        return is_numeric($ticks) ? (float) $ticks / self::TICKS_PER_CENT : null;
    }

    /**
     * Server-side tool usage keyed like XAiPricing::toolCallPricing().
     *
     * @param  array<string, mixed>  $usage
     * @return array<string, int>
     */
    public static function toolCalls(array $usage): array
    {
        $details = $usage['server_side_tool_usage_details'] ?? null;

        if (! is_array($details)) {
            return [];
        }

        $counts = [];

        foreach (self::TOOL_KEYS as $field => $key) {
            $count = Payload::int($details[$field] ?? null);

            if ($count > 0) {
                $counts[$key] = $count;
            }
        }

        return $counts;
    }

    /**
     * Tokens of an image or video job (spent by the prompt-rewriting model).
     *
     * Unlike chat, these totals already include reasoning and image tokens:
     * `output_tokens` is the sum of `output_tokens_details`, and
     * `cached_tokens` is a subset of the input text tokens.
     *
     * @param  array<string, mixed>  $usage
     */
    public static function mediaTokens(array $usage): TokenMetrics
    {
        $input = is_array($usage['input_tokens_details'] ?? null) ? $usage['input_tokens_details'] : [];
        $output = is_array($usage['output_tokens_details'] ?? null) ? $usage['output_tokens_details'] : [];

        return new TokenMetrics(
            promptTokens: Payload::int($usage['input_tokens'] ?? null),
            completionTokens: Payload::int($usage['output_tokens'] ?? null),
            cachedTokens: Payload::int($input['cached_tokens'] ?? null),
            reasoningTokens: Payload::int($output['reasoning_tokens'] ?? null),
            imageInputTokens: Payload::int($input['image_tokens'] ?? null),
            imageOutputTokens: Payload::int($output['image_tokens'] ?? null),
        );
    }
}
