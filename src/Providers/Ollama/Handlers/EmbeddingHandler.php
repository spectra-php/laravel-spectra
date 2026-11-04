<?php

declare(strict_types=1);

namespace Spectra\Providers\Ollama\Handlers;

use Spectra\Concerns\ExtractsModelField;
use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Support\Payload;

/**
 * Ollama's native `/api/embed` endpoint, which reports its input tokens as
 * `prompt_eval_count`.
 */
class EmbeddingHandler implements ExtractsModelFromResponse, Handler, MatchesResponseShape
{
    use ExtractsModelField;
    use MatchesEndpoints;

    /**
     * Get the model type Ollama embedding requests are recorded as.
     */
    public function modelType(): ModelType
    {
        return ModelType::Embedding;
    }

    /**
     * Get Ollama's native embed endpoint path.
     */
    public function endpoints(): array
    {
        return ['/api/embed'];
    }

    /**
     * Build the input tokens of an Ollama embed response.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        return new Metrics(
            tokens: new TokenMetrics(
                promptTokens: Payload::int($responseData['prompt_eval_count'] ?? null),
            ),
        );
    }

    /**
     * Describe an Ollama embed response by its vector count and dimensions.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        if (! isset($response['embeddings']) || ! is_array($response['embeddings'])) {
            return null;
        }

        $count = count($response['embeddings']);
        $dimensions = isset($response['embeddings'][0]) ? count($response['embeddings'][0]) : 0;

        return "[embedding: {$count} vectors, {$dimensions} dimensions]";
    }

    /**
     * Determine if the body is an Ollama embed response.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchesResponse(array $data): bool
    {
        return isset($data['model'], $data['embeddings']);
    }
}
