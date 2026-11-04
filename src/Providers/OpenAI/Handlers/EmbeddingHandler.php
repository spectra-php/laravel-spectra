<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenAI\Handlers;

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
 * Embeddings (`/v1/embeddings`), billed on input tokens only.
 */
class EmbeddingHandler implements ExtractsModelFromResponse, Handler, MatchesResponseShape
{
    use ExtractsModelField;
    use MatchesEndpoints;

    /**
     * Get the model type this handler records.
     */
    public function modelType(): ModelType
    {
        return ModelType::Embedding;
    }

    /**
     * Get the embedding endpoint paths.
     */
    public function endpoints(): array
    {
        return [
            '/v1/embeddings',
        ];
    }

    /**
     * Get the input tokens of an embedding response.
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
     * Get a placeholder text naming the embedding's dimensions.
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
     * Determine if the body is an embedding list.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchesResponse(array $data): bool
    {
        return ($data['object'] ?? null) === 'list' && isset($data['data'][0]['embedding']);
    }
}
