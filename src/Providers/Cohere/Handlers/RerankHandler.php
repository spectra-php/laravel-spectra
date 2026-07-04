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

class RerankHandler implements ExtractsModelFromResponse, Handler, MatchesResponseShape
{
    use ExtractsModelField;
    use MatchesEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Text;
    }

    public function endpoints(): array
    {
        return [
            '/v2/rerank',
            '/v1/rerank',
        ];
    }

    /**
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
                count: (int) ($billed['search_units'] ?? 1),
            ),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        $results = $response['results'] ?? null;

        if (! is_array($results)) {
            return null;
        }

        return '[rerank: '.count($results).' results]';
    }

    /** @param  array<string, mixed>  $data */
    public function matchesResponse(array $data): bool
    {
        return isset($data['results'][0]['relevance_score']);
    }
}
