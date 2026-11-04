<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenAI;

use Spectra\Data\TokenMetrics;

/**
 * Turns the usage objects of OpenAI's APIs into TokenMetrics.
 *
 * OpenAI reports usage in several shapes: Chat Completions (`prompt_tokens`,
 * `prompt_tokens_details`), Responses (`input_tokens`, `input_tokens_details`),
 * the Images API (image vs text tokens) and transcriptions
 * (`input_token_details`, or a billed `duration`). In every shape the input
 * total already contains cached tokens and cache writes, and the output total
 * already contains reasoning tokens, which matches the TokenMetrics contract,
 * so the totals are passed through and only the breakdowns are read.
 *
 * GPT-5.6 and later split cache writes by lifetime in
 * `cache_write_tokens_details` (`ttl_30m_tokens`, `ttl_12h_tokens`). The 12h
 * writes are reported as cacheWrite1hTokens, so they bill at the catalog's
 * `cacheWrite1hPrice` and the 30m writes at `cacheWrite5mPrice`.
 */
final class OpenAiUsage
{
    /**
     * Pick the parser from the usage keys (Responses-style or Chat-style).
     *
     * @param  array<string, mixed>  $usage
     */
    public static function fromAny(array $usage): TokenMetrics
    {
        if (isset($usage['input_tokens']) && ! isset($usage['prompt_tokens'])) {
            return self::fromResponses($usage);
        }

        return self::fromChatCompletions($usage);
    }

    /**
     * Chat Completions and legacy Completions usage.
     *
     * @param  array<string, mixed>  $usage
     */
    public static function fromChatCompletions(array $usage): TokenMetrics
    {
        $input = self::details($usage, 'prompt_tokens_details');
        $output = self::details($usage, 'completion_tokens_details');
        $cachedByModality = self::details($input, 'cached_tokens_details');

        return new TokenMetrics(
            promptTokens: self::int($usage, 'prompt_tokens', 'input_tokens'),
            completionTokens: self::int($usage, 'completion_tokens', 'output_tokens'),
            cachedTokens: self::int($input, 'cached_tokens'),
            reasoningTokens: self::int($output, 'reasoning_tokens'),
            cacheWriteTokens: self::int($input, 'cache_write_tokens'),
            cacheWrite1hTokens: self::cacheWrites12h($input),
            audioInputTokens: self::uncached($input, $cachedByModality, 'audio_tokens'),
            imageInputTokens: self::uncached($input, $cachedByModality, 'image_tokens'),
            audioOutputTokens: self::int($output, 'audio_tokens'),
            imageOutputTokens: self::int($output, 'image_tokens'),
        );
    }

    /**
     * Responses API usage (also used by `/v1/responses/compact`).
     *
     * @param  array<string, mixed>  $usage
     */
    public static function fromResponses(array $usage): TokenMetrics
    {
        $input = self::details($usage, 'input_tokens_details');
        $output = self::details($usage, 'output_tokens_details');
        $cachedByModality = self::details($input, 'cached_tokens_details');

        return new TokenMetrics(
            promptTokens: self::int($usage, 'input_tokens'),
            completionTokens: self::int($usage, 'output_tokens'),
            // Some examples flatten the details next to the totals; accept both.
            cachedTokens: self::int($input, 'cached_tokens') ?: self::int($usage, 'cached_tokens'),
            reasoningTokens: self::int($output, 'reasoning_tokens') ?: self::int($usage, 'reasoning_tokens'),
            cacheWriteTokens: self::int($input, 'cache_write_tokens') ?: self::int($usage, 'cache_write_tokens'),
            cacheWrite1hTokens: self::cacheWrites12h($input),
            audioInputTokens: self::uncached($input, $cachedByModality, 'audio_tokens'),
            imageInputTokens: self::uncached($input, $cachedByModality, 'image_tokens'),
            audioOutputTokens: self::int($output, 'audio_tokens'),
            imageOutputTokens: self::int($output, 'image_tokens'),
        );
    }

    /**
     * Images API usage (`/v1/images/*` and their streamed `*.completed` events).
     *
     * GPT Image models bill image output tokens at their own rate. When the
     * response has no `output_tokens_details`, every output token is an image
     * token; when it does, the text part (gpt-image-1.5 reasoning) bills at
     * the text output rate.
     *
     * @param  array<string, mixed>  $usage
     */
    public static function fromImages(array $usage): TokenMetrics
    {
        $input = self::details($usage, 'input_tokens_details');
        $output = self::details($usage, 'output_tokens_details');
        $cachedByModality = self::details($input, 'cached_tokens_details');
        $outputTokens = self::int($usage, 'output_tokens');

        return new TokenMetrics(
            promptTokens: self::int($usage, 'input_tokens'),
            completionTokens: $outputTokens,
            cachedTokens: self::int($input, 'cached_tokens'),
            imageInputTokens: self::uncached($input, $cachedByModality, 'image_tokens'),
            imageOutputTokens: $output === [] ? $outputTokens : self::int($output, 'image_tokens'),
        );
    }

    /**
     * Transcription usage of the `tokens` type. The `duration` type carries
     * no tokens (see {@see transcriptionSeconds()}).
     *
     * @param  array<string, mixed>  $usage
     */
    public static function fromTranscription(array $usage): TokenMetrics
    {
        // The transcription API spells it `input_token_details`.
        $input = self::details($usage, 'input_token_details') ?: self::details($usage, 'input_tokens_details');

        return new TokenMetrics(
            promptTokens: self::int($usage, 'input_tokens', 'prompt_tokens'),
            completionTokens: self::int($usage, 'output_tokens', 'completion_tokens'),
            audioInputTokens: self::int($input, 'audio_tokens'),
        );
    }

    /**
     * Billed seconds of a `duration`-type transcription usage, if any.
     *
     * @param  array<string, mixed>  $usage
     */
    public static function transcriptionSeconds(array $usage): ?float
    {
        if (($usage['type'] ?? null) !== 'duration' || ! is_numeric($usage['seconds'] ?? null)) {
            return null;
        }

        return (float) $usage['seconds'];
    }

    /**
     * Get a nested usage details object, or an empty array.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function details(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /**
     * The first numeric value among the given keys, or 0.
     *
     * @param  array<string, mixed>  $data
     */
    private static function int(array $data, string ...$keys): int
    {
        foreach ($keys as $key) {
            if (is_numeric($data[$key] ?? null)) {
                return max(0, (int) $data[$key]);
            }
        }

        return 0;
    }

    /**
     * Cache writes with the 12-hour lifetime, capped at the write total.
     *
     * @param  array<string, mixed>  $input
     */
    private static function cacheWrites12h(array $input): int
    {
        $writes = self::details($input, 'cache_write_tokens_details');

        return min(self::int($writes, 'ttl_12h_tokens'), self::int($input, 'cache_write_tokens'));
    }

    /**
     * Tokens of one modality minus the cached ones, because TokenMetrics
     * counts audio and image input as uncached (cache reads have their own
     * rate and are counted in cachedTokens).
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $cachedByModality
     */
    private static function uncached(array $input, array $cachedByModality, string $key): int
    {
        return max(0, self::int($input, $key) - self::int($cachedByModality, $key));
    }
}
