<?php

declare(strict_types=1);

namespace Spectra\Providers\Cohere\Handlers;

use Spectra\Concerns\ExtractsModelField;
use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\Metrics;
use Spectra\Data\SearchMetrics;
use Spectra\Enums\ModelType;
use Spectra\Support\Payload;

/**
 * Cohere Rerank (`/v2/rerank`, `/v1/rerank`), billed per search unit: one
 * query with up to 100 documents (long documents are split into chunks that
 * count as extra documents).
 */
class RerankHandler implements ExtractsModelFromResponse, Handler, MatchesResponseShape
{
    use ExtractsModelField;
    use MatchesEndpoints;

    /**
     * Get the model type Cohere rerank requests are recorded as.
     */
    public function modelType(): ModelType
    {
        return ModelType::Text;
    }

    /**
     * Get the Cohere v1 and v2 rerank endpoint paths.
     */
    public function endpoints(): array
    {
        return [
            '/v2/rerank',
            '/v1/rerank',
        ];
    }

    /**
     * Build the billed search units of a Cohere rerank response.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $billed = $responseData['meta']['billed_units']
            ?? $responseData['usage']['billed_units']
            ?? [];

        return new Metrics(
            search: new SearchMetrics(
                count: Payload::int($billed['search_units'] ?? null, 1),
            ),
        );
    }

    /**
     * Describe a Cohere rerank response by its number of results.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        $results = $response['results'] ?? null;

        if (! is_array($results)) {
            return null;
        }

        return '[rerank: '.count($results).' results]';
    }

    /**
     * Determine if the body is a Cohere rerank response with relevance scores.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchesResponse(array $data): bool
    {
        return isset($data['results'][0]['relevance_score']);
    }
}
