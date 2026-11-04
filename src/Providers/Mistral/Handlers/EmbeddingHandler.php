<?php

declare(strict_types=1);

namespace Spectra\Providers\Mistral\Handlers;

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
 * Mistral embeddings (`/v1/embeddings`). The usage has no completion count,
 * so it is taken as `total_tokens` minus `prompt_tokens`.
 */
class EmbeddingHandler implements ExtractsModelFromResponse, Handler, MatchesResponseShape
{
    use ExtractsModelField;
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
                promptTokens: Payload::int($responseData['usage']['prompt_tokens'] ?? null),
                completionTokens: max(0, Payload::int($responseData['usage']['total_tokens'] ?? null) - Payload::int($responseData['usage']['prompt_tokens'] ?? null)),
            ),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        $embedding = data_get($response, 'data.0.embedding');

        if (! is_array($embedding)) {
            return null;
        }

        $dimensions = count($embedding);

        return "[embedding: {$dimensions} dimensions]";
    }

    /** @param  array<string, mixed>  $data */
    public function matchesResponse(array $data): bool
    {
        return ($data['object'] ?? null) === 'list' && isset($data['data'][0]['embedding']);
    }
}
