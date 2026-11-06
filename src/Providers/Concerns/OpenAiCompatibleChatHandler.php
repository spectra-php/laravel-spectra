<?php

declare(strict_types=1);

namespace Spectra\Providers\Concerns;

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
use Spectra\Support\Payload;
use Spectra\Support\Tracking\StreamHandler;

/**
 * Base handler for providers that expose an OpenAI-compatible
 * `chat/completions` endpoint (Groq, Mistral, xAI, OpenRouter, …).
 *
 * Subclasses normally only need to declare their endpoint paths via
 * {@see endpoints()}. Override the protected hooks when the provider deviates
 * from the OpenAI shape:
 *
 * - {@see reasoningTokensAreSeparate()} when reasoning tokens are reported
 *   outside `completion_tokens` (xAI) and must be added to the billed output.
 * - {@see reportedCostInCents()} when the response carries the charged cost
 *   (OpenRouter `usage.cost`, xAI `cost_in_usd_ticks`).
 * - {@see usageFrom()} when usage lives somewhere else in the payload.
 */
abstract class OpenAiCompatibleChatHandler implements ExtractsModelFromResponse, Handler, HasFinishReason, MatchesResponseShape, StreamsResponse
{
    use ExtractsModelField;
    use MatchesEndpoints;

    /**
     * Get the model type this handler records.
     */
    public function modelType(): ModelType
    {
        return ModelType::Text;
    }

    /**
     * Get the provider's chat completion endpoint paths.
     *
     * @return array<string>
     */
    abstract public function endpoints(): array;

    /**
     * Get the token usage and reported cost of a chat completion.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $usage = $this->usageFrom($responseData);

        return new Metrics(
            tokens: $usage !== null ? $this->tokensFromUsage($usage) : new TokenMetrics,
            reportedCostInCents: $usage !== null ? $this->reportedCostInCents($usage, $responseData) : null,
        );
    }

    /**
     * Normalise an OpenAI-style usage object to TokenMetrics.
     *
     * @param  array<string, mixed>  $usage
     */
    public function tokensFromUsage(array $usage): TokenMetrics
    {
        $promptDetails = is_array($usage['prompt_tokens_details'] ?? null) ? $usage['prompt_tokens_details'] : [];
        $completionDetails = is_array($usage['completion_tokens_details'] ?? null) ? $usage['completion_tokens_details'] : [];

        $reasoning = Payload::int($completionDetails['reasoning_tokens'] ?? $usage['reasoning_tokens'] ?? null);
        $completion = Payload::int($usage['completion_tokens'] ?? $usage['output_tokens'] ?? null);

        if ($this->reasoningTokensAreSeparate()) {
            $completion += $reasoning;
        }

        return new TokenMetrics(
            promptTokens: Payload::int($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null),
            completionTokens: $completion,
            cachedTokens: Payload::int($promptDetails['cached_tokens'] ?? null),
            reasoningTokens: $reasoning,
            cacheWriteTokens: Payload::int($promptDetails['cache_write_tokens'] ?? null),
            audioInputTokens: Payload::int($promptDetails['audio_tokens'] ?? null),
            audioOutputTokens: Payload::int($completionDetails['audio_tokens'] ?? null),
        );
    }

    /**
     * Get the usage object of a response or stream chunk.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public function usageFrom(array $data): ?array
    {
        $usage = $data['usage'] ?? $data['x_groq']['usage'] ?? null;

        return is_array($usage) ? $usage : null;
    }

    /**
     * Determine if `completion_tokens_details.reasoning_tokens` is billed on top
     * of `completion_tokens` (true for xAI) rather than included in it (OpenAI).
     */
    protected function reasoningTokensAreSeparate(): bool
    {
        return false;
    }

    /**
     * Get the cost the provider reports for this request, in US cents.
     *
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $responseData
     */
    public function reportedCostInCents(array $usage, array $responseData): ?float
    {
        return null;
    }

    /**
     * Get the first choice's text.
     *
     * Some providers (Mistral with reasoning, for one) send `content` as a
     * list of typed parts instead of a string.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        $content = data_get($response, 'choices.0.message.content') ?? data_get($response, 'choices.0.text');

        if (is_string($content)) {
            return $content;
        }

        if (! is_array($content)) {
            return null;
        }

        $texts = collect($content)
            ->map(fn (mixed $part) => is_array($part) ? ($part['text'] ?? null) : $part)
            ->filter(fn (mixed $text) => filled($text));

        return $texts->isNotEmpty() ? $texts->implode("\n") : null;
    }

    /**
     * Get the first choice's finish reason.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractFinishReason(array $response): ?string
    {
        return data_get($response, 'choices.0.finish_reason');
    }

    /**
     * Determine if the body is a chat completion.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchesResponse(array $data): bool
    {
        return ($data['object'] ?? null) === 'chat.completion';
    }

    /**
     * Get the stream handler for a streamed chat completion.
     */
    public function streamingHandler(): StreamHandler
    {
        return new OpenAiCompatibleStreaming($this);
    }
}
