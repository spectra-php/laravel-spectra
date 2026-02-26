<?php

namespace Spectra\Providers\Ollama\Handlers;

use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;

class EmbeddingHandler implements ExtractsModelFromResponse, Handler, MatchesResponseShape
{
    use MatchesEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Embedding;
    }

    public function endpoints(): array
    {
        return ['/api/embed'];
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
        if (! isset($response['embeddings']) || ! is_array($response['embeddings'])) {
            return null;
        }

        $count = count($response['embeddings']);
        $dimensions = isset($response['embeddings'][0]) ? count($response['embeddings'][0]) : 0;

        return "[embedding: {$count} vectors, {$dimensions} dimensions]";
    }

    /** @param  array<string, mixed>  $data */
    public function matchesResponse(array $data): bool
    {
        return isset($data['model'], $data['embeddings']);
    }
}
