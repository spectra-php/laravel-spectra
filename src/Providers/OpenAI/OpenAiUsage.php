<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenAI;

use Spectra\Data\TokenMetrics;
use Spectra\Support\Payload;

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
     * Get the token metrics of a Chat Completions or legacy Completions usage object.
     *
     * @param  array<string, mixed>  $usage
     */
    public static function fromChatCompletions(array $usage): TokenMetrics
    {
        $input = self::readDetails($usage, 'prompt_tokens_details');
        $output = self::readDetails($usage, 'completion_tokens_details');
        $cachedByModality = self::readDetails($input, 'cached_tokens_details');

        return new TokenMetrics(
            promptTokens: self::readInt($usage, 'prompt_tokens', 'input_tokens'),
            completionTokens: self::readInt($usage, 'completion_tokens', 'output_tokens'),
            cachedTokens: self::readInt($input, 'cached_tokens'),
            reasoningTokens: self::readInt($output, 'reasoning_tokens'),
            cacheWriteTokens: self::readInt($input, 'cache_write_tokens'),
            cacheWrite1hTokens: self::countCacheWrites12h($input),
            audioInputTokens: self::countUncached($input, $cachedByModality, 'audio_tokens'),
            imageInputTokens: self::countUncached($input, $cachedByModality, 'image_tokens'),
            audioOutputTokens: self::readInt($output, 'audio_tokens'),
            imageOutputTokens: self::readInt($output, 'image_tokens'),
        );
    }

    /**
     * Get the token metrics of a Responses API usage object (also used by `/v1/responses/compact`).
     *
     * @param  array<string, mixed>  $usage
     */
    public static function fromResponses(array $usage): TokenMetrics
    {
        $input = self::readDetails($usage, 'input_tokens_details');
        $output = self::readDetails($usage, 'output_tokens_details');
        $cachedByModality = self::readDetails($input, 'cached_tokens_details');

        return new TokenMetrics(
            promptTokens: self::readInt($usage, 'input_tokens'),
            completionTokens: self::readInt($usage, 'output_tokens'),
            // Some examples flatten the details next to the totals; accept both.
            cachedTokens: self::readInt($input, 'cached_tokens') ?: self::readInt($usage, 'cached_tokens'),
            reasoningTokens: self::readInt($output, 'reasoning_tokens') ?: self::readInt($usage, 'reasoning_tokens'),
            cacheWriteTokens: self::readInt($input, 'cache_write_tokens') ?: self::readInt($usage, 'cache_write_tokens'),
            cacheWrite1hTokens: self::countCacheWrites12h($input),
            audioInputTokens: self::countUncached($input, $cachedByModality, 'audio_tokens'),
            imageInputTokens: self::countUncached($input, $cachedByModality, 'image_tokens'),
            audioOutputTokens: self::readInt($output, 'audio_tokens'),
            imageOutputTokens: self::readInt($output, 'image_tokens'),
        );
    }

    /**
     * Get the token metrics of an Images API usage object (`/v1/images/*` and their streamed `*.completed` events).
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
        $input = self::readDetails($usage, 'input_tokens_details');
        $output = self::readDetails($usage, 'output_tokens_details');
        $cachedByModality = self::readDetails($input, 'cached_tokens_details');
        $outputTokens = self::readInt($usage, 'output_tokens');

        return new TokenMetrics(
            promptTokens: self::readInt($usage, 'input_tokens'),
            completionTokens: $outputTokens,
            cachedTokens: self::readInt($input, 'cached_tokens'),
            imageInputTokens: self::countUncached($input, $cachedByModality, 'image_tokens'),
            imageOutputTokens: blank($output) ? $outputTokens : self::readInt($output, 'image_tokens'),
        );
    }

    /**
     * Get the token metrics of a `tokens`-type transcription usage object.
     *
     * The `duration` type carries no tokens (see {@see transcriptionSeconds()}).
     *
     * @param  array<string, mixed>  $usage
     */
    public static function fromTranscription(array $usage): TokenMetrics
    {
        // The transcription API spells it `input_token_details`.
        $input = self::readDetails($usage, 'input_token_details') ?: self::readDetails($usage, 'input_tokens_details');

        return new TokenMetrics(
            promptTokens: self::readInt($usage, 'input_tokens', 'prompt_tokens'),
            completionTokens: self::readInt($usage, 'output_tokens', 'completion_tokens'),
            audioInputTokens: self::readInt($input, 'audio_tokens'),
        );
    }

    /**
     * Get the billed seconds of a `duration`-type transcription usage, if any.
     *
     * @param  array<string, mixed>  $usage
     */
    public static function transcriptionSeconds(array $usage): ?float
    {
        if (($usage['type'] ?? null) !== 'duration' || ! is_numeric($usage['seconds'] ?? null)) {
            return null;
        }

        return Payload::float($usage['seconds']);
    }

    /**
     * Get a nested usage details object, or an empty array.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function readDetails(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /**
     * Get the first numeric value among the given keys, never below 0, or 0 when none is numeric.
     *
     * @param  array<string, mixed>  $data
     */
    private static function readInt(array $data, string ...$keys): int
    {
        foreach ($keys as $key) {
            if (is_numeric($data[$key] ?? null)) {
                return max(0, Payload::int($data[$key]));
            }
        }

        return 0;
    }

    /**
     * Count the cache writes with the 12-hour lifetime, capped at the write total.
     *
     * @param  array<string, mixed>  $input
     */
    private static function countCacheWrites12h(array $input): int
    {
        $writes = self::readDetails($input, 'cache_write_tokens_details');

        return min(self::readInt($writes, 'ttl_12h_tokens'), self::readInt($input, 'cache_write_tokens'));
    }

    /**
     * Count the tokens of one modality minus the cached ones.
     *
     * TokenMetrics counts audio and image input as uncached (cache reads
     * have their own rate and are counted in cachedTokens).
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $cachedByModality
     */
    private static function countUncached(array $input, array $cachedByModality, string $key): int
    {
        return max(0, self::readInt($input, $key) - self::readInt($cachedByModality, $key));
    }
}
