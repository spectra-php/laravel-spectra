<?php

declare(strict_types=1);

namespace Spectra\Providers\Scaleway\Handlers;

use Spectra\Concerns\ExtractsModelField;
use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Support\Payload;

/**
 * Scaleway embeddings (`/v1/embeddings`, optionally under a project id),
 * billed per input token.
 */
class EmbeddingHandler implements ExtractsModelFromResponse, Handler, MatchesResponseShape
{
    use ExtractsModelField;
    use MatchesParametricEndpoints;

    /**
     * Get the model type Scaleway embedding requests are recorded as.
     */
    public function modelType(): ModelType
    {
        return ModelType::Embedding;
    }

    /**
     * Get the Scaleway embeddings paths, with or without a project id.
     */
    public function endpoints(): array
    {
        return [
            '/v1/embeddings',
            '/{project_id}/v1/embeddings',
        ];
    }

    /**
     * Build the prompt tokens of a Scaleway embeddings response.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        return new Metrics(
            tokens: new TokenMetrics(
                promptTokens: Payload::int($responseData['usage']['prompt_tokens'] ?? null),
                completionTokens: max(0, Payload::int($responseData['usage']['total_tokens'] ?? null) - Payload::int($responseData['usage']['prompt_tokens'] ?? null)),
            ),
        );
    }

    /**
     * Describe the first embedding of a Scaleway response by its dimensions.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        if (! isset($response['data'][0]['embedding'])) {
            return null;
        }

        $dimensions = count($response['data'][0]['embedding']);

        return "[embedding: {$dimensions} dimensions]";
    }

    /**
     * Determine if the body is a Scaleway embeddings list.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchesResponse(array $data): bool
    {
        return ($data['object'] ?? null) === 'list' && isset($data['data'][0]['embedding']);
    }
}
