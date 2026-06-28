<?php

declare(strict_types=1);

namespace Spectra\Providers\Concerns;

use Spectra\Concerns\ExtractsModelField;
use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasFinishReason;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;

/**
 * Base handler for providers that expose an OpenAI-compatible
 * `chat/completions` endpoint (Groq, Mistral, xAI, OpenRouter, …).
 *
 * Subclasses normally only need to declare their endpoint paths via
 * {@see endpoints()}. Override {@see matchesResponse()} or any extraction
 * method when the provider deviates from the OpenAI shape.
 */
abstract class OpenAiCompatibleChatHandler implements ExtractsModelFromResponse, Handler, HasFinishReason, MatchesResponseShape
{
    use ExtractsModelField;
    use MatchesEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Text;
    }

    /** @return array<string> */
    abstract public function endpoints(): array;

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        return new Metrics(
            tokens: new TokenMetrics(
                promptTokens: (int) ($responseData['usage']['prompt_tokens'] ?? 0),
                completionTokens: (int) ($responseData['usage']['completion_tokens'] ?? 0),
                cachedTokens: (int) ($responseData['usage']['prompt_tokens_details']['cached_tokens'] ?? 0),
            ),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        return $response['choices'][0]['message']['content'] ?? $response['choices'][0]['text'] ?? null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractFinishReason(array $response): ?string
    {
        return $response['choices'][0]['finish_reason'] ?? null;
    }

    /** @param  array<string, mixed>  $data */
    public function matchesResponse(array $data): bool
    {
        return ($data['object'] ?? null) === 'chat.completion';
    }
}
