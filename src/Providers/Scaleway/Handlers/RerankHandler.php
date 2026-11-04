<?php

declare(strict_types=1);

namespace Spectra\Providers\Scaleway\Handlers;

use Spectra\Concerns\ExtractsModelField;
use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Data\Metrics;
use Spectra\Data\SearchMetrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Support\Payload;

/**
 * Scaleway reranking (`/v1/rerank`, optionally under a project id). Scaleway
 * reranks with an embedding model and bills its tokens
 * (`usage.total_tokens`) at that model's embedding price.
 */
class RerankHandler implements ExtractsModelFromResponse, Handler
{
    use ExtractsModelField;
    use MatchesParametricEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Text;
    }

    public function endpoints(): array
    {
        return [
            '/v1/rerank',
            '/{project_id}/v1/rerank',
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
                promptTokens: Payload::int($responseData['usage']['prompt_tokens'] ?? $responseData['usage']['total_tokens'] ?? null),
                completionTokens: 0,
            ),
            search: new SearchMetrics(count: 1),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        $results = $response['data'] ?? $response['results'] ?? null;

        if (! is_array($results)) {
            return null;
        }

        return '[rerank: '.count($results).' results]';
    }
}
