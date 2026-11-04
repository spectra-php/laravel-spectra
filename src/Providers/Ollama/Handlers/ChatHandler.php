<?php

declare(strict_types=1);

namespace Spectra\Providers\Ollama\Handlers;

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
use Spectra\Providers\Ollama\Streaming\ChatStreaming;
use Spectra\Support\Payload;
use Spectra\Support\Tracking\StreamHandler;

/**
 * Ollama's native `/api/chat` and `/api/generate` endpoints.
 *
 * Ollama reports usage as `prompt_eval_count` / `eval_count` instead of an
 * OpenAI-style usage object; its OpenAI-compatible `/v1` endpoints are not
 * handled here.
 */
class ChatHandler implements ExtractsModelFromResponse, Handler, HasFinishReason, MatchesResponseShape, StreamsResponse
{
    use ExtractsModelField;
    use MatchesEndpoints;

    /**
     * Get the model type Ollama chat requests are recorded as.
     */
    public function modelType(): ModelType
    {
        return ModelType::Text;
    }

    /**
     * Get Ollama's native chat and generate endpoint paths.
     */
    public function endpoints(): array
    {
        return ['/api/chat', '/api/generate'];
    }

    /**
     * Build the token usage from Ollama's `prompt_eval_count` and `eval_count`.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        return new Metrics(
            tokens: new TokenMetrics(
                promptTokens: Payload::int($responseData['prompt_eval_count'] ?? null),
                completionTokens: Payload::int($responseData['eval_count'] ?? null),
            ),
        );
    }

    /**
     * Get the generated text of an Ollama generate or chat response.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        return $response['response'] ?? $response['message']['content'] ?? null;
    }

    /**
     * Get the `done_reason` of an Ollama response.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractFinishReason(array $response): ?string
    {
        return $response['done_reason'] ?? null;
    }

    /**
     * Determine if the body is a native Ollama chat or generate response.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchesResponse(array $data): bool
    {
        return isset($data['model']) && (isset($data['done']) || isset($data['message']));
    }

    /**
     * Create the stream handler for Ollama's newline-delimited JSON streams.
     */
    public function streamingHandler(): StreamHandler
    {
        return new ChatStreaming;
    }
}
