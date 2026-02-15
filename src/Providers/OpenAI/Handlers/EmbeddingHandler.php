<?php

namespace Spectra\Providers\OpenAI\Handlers;

use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\Handler;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;

class EmbeddingHandler implements Handler, MatchesResponseShape
{
    use MatchesEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Embedding;
    }

    public function endpoints(): array
    {
        return [
            '/v1/embeddings',
        ];
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        return new Metrics(
            tokens: new TokenMetrics(
                promptTokens: (int) ($responseData['usage']['prompt_tokens'] ?? 0),
                completionTokens: max(0, (int) ($responseData['usage']['total_tokens'] ?? 0) - (int) ($responseData['usage']['prompt_tokens'] ?? 0)),
            ),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractModel(array $response): ?string
    {
        return $response['model'] ?? null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        if (! isset($response['data'][0]['embedding'])) {
            return null;
        }

        $dimensions = count($response['data'][0]['embedding']);

        return "[embedding: {$dimensions} dimensions]";
    }

    /** @param  array<string, mixed>  $data */
    public function matchesResponse(array $data): bool
    {
        return ($data['object'] ?? null) === 'list' && isset($data['data'][0]['embedding']);
    }
}
