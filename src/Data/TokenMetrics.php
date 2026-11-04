<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * Token usage for one request, normalised across providers.
 *
 * Every provider reports usage differently (Anthropic leaves cache tokens out
 * of `input_tokens`, Gemini leaves thinking tokens out of the candidates
 * count, ...). Handlers convert to this one shape so the cost calculator can
 * stay provider-agnostic:
 *
 * - `promptTokens` is the TOTAL input: uncached + cache reads + cache writes.
 * - `completionTokens` is the TOTAL output, including reasoning tokens.
 * - Every other field is a breakdown that is already part of one of the two
 *   totals above, so nothing is ever counted twice.
 */
readonly class TokenMetrics extends DataTransferObject
{
    /**
     * Create a new token metrics instance.
     */
    public function __construct(
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        /** Input tokens read from the provider's prompt cache (part of promptTokens). */
        public int $cachedTokens = 0,
        /** Output tokens spent on reasoning / thinking (part of completionTokens). */
        public int $reasoningTokens = 0,
        /** Input tokens written to the prompt cache (part of promptTokens). */
        public int $cacheWriteTokens = 0,
        /** Cache writes with a 1-hour TTL (part of cacheWriteTokens); the rest bill at the 5-minute rate. */
        public int $cacheWrite1hTokens = 0,
        /** Uncached audio input tokens (part of promptTokens). */
        public int $audioInputTokens = 0,
        /** Uncached image input tokens (part of promptTokens). */
        public int $imageInputTokens = 0,
        /** Audio output tokens (part of completionTokens). */
        public int $audioOutputTokens = 0,
        /** Image output tokens (part of completionTokens). */
        public int $imageOutputTokens = 0,
    ) {}

    /**
     * Build from the snake_case usage arrays that stream handlers and manual
     * tracking use (`prompt_tokens`, `cached_tokens`, `cache_write_tokens`, ...).
     *
     * @param  array<string, mixed>  $usage
     */
    public static function fromArray(array $usage): self
    {
        $int = static fn (string ...$keys): int => (int) (collect($keys)
            ->map(fn (string $key) => $usage[$key] ?? null)
            ->first(fn ($value) => is_numeric($value)) ?? 0);

        return new self(
            promptTokens: $int('prompt_tokens', 'input_tokens'),
            completionTokens: $int('completion_tokens', 'output_tokens'),
            cachedTokens: $int('cached_tokens', 'cache_read_tokens'),
            reasoningTokens: $int('reasoning_tokens'),
            cacheWriteTokens: $int('cache_write_tokens'),
            cacheWrite1hTokens: $int('cache_write_1h_tokens'),
            audioInputTokens: $int('audio_input_tokens'),
            imageInputTokens: $int('image_input_tokens'),
            audioOutputTokens: $int('audio_output_tokens'),
            imageOutputTokens: $int('image_output_tokens'),
        );
    }

    /**
     * The snake_case usage array understood by fromArray().
     *
     * @return array<string, int>
     */
    public function toUsageArray(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'cached_tokens' => $this->cachedTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
            'cache_write_1h_tokens' => $this->cacheWrite1hTokens,
            'audio_input_tokens' => $this->audioInputTokens,
            'image_input_tokens' => $this->imageInputTokens,
            'audio_output_tokens' => $this->audioOutputTokens,
            'image_output_tokens' => $this->imageOutputTokens,
        ];
    }

    /**
     * Get the input and output tokens added together.
     */
    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
