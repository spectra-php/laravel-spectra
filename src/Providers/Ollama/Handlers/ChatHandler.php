<?php

namespace Spectra\Providers\Ollama\Handlers;

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
use Spectra\Support\Tracking\StreamHandler;

class ChatHandler implements ExtractsModelFromResponse, Handler, HasFinishReason, MatchesResponseShape, StreamsResponse
{
    use MatchesEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Text;
    }

    public function endpoints(): array
    {
        return ['/api/chat', '/api/generate'];
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        return new Metrics(
            tokens: new TokenMetrics(
                promptTokens: (int) ($responseData['prompt_eval_count'] ?? 0),
                completionTokens: (int) ($responseData['eval_count'] ?? 0),
            ),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractModelFromResponse(array $response): ?string
    {
        return $response['model'] ?? null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        return $response['response'] ?? $response['message']['content'] ?? null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractFinishReason(array $response): ?string
    {
        return $response['done_reason'] ?? null;
    }

    /** @param  array<string, mixed>  $data */
    public function matchesResponse(array $data): bool
    {
        return isset($data['model']) && (isset($data['done']) || isset($data['message']));
    }

    public function streamingHandler(): StreamHandler
    {
        return new ChatStreaming;
    }
}
