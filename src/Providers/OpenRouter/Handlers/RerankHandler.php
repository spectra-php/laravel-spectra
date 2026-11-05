<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenRouter\Handlers;

use Spectra\Concerns\ExtractsModelField;
use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\Metrics;
use Spectra\Data\SearchMetrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\OpenRouter\Concerns\ReadsReportedCost;
use Spectra\Support\Payload;

/**
 * OpenRouter reranking (`/api/v1/rerank`). Cohere models bill search units
 * (`usage.search_units`), others tokens (`usage.total_tokens`); OpenRouter
 * reports the price either way in `usage.cost`.
 */
class RerankHandler implements ExtractsModelFromResponse, Handler, MatchesResponseShape
{
    use ExtractsModelField;
    use MatchesEndpoints;
    use ReadsReportedCost;

    /**
     * Get the model type this handler records.
     */
    public function modelType(): ModelType
    {
        return ModelType::Text;
    }

    /**
     * Get the rerank endpoint paths.
     */
    public function endpoints(): array
    {
        return ['/api/v1/rerank'];
    }

    /**
     * Get the tokens, search units and reported cost of a rerank response.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $usage = is_array($responseData['usage'] ?? null) ? $responseData['usage'] : [];
        $tokens = Payload::int($usage['total_tokens'] ?? null);

        return new Metrics(
            tokens: $tokens > 0 ? new TokenMetrics(promptTokens: $tokens, completionTokens: 0) : null,
            search: new SearchMetrics(count: max(1, Payload::int($usage['search_units'] ?? null, 1))),
            reportedCostInCents: $this->costFromUsage($usage),
        );
    }

    /**
     * Get a placeholder text naming the number of rerank results.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        $results = $response['results'] ?? null;

        if (! is_array($results)) {
            return null;
        }

        $count = count($results);

        return "[rerank: {$count} results]";
    }

    /**
     * Determine if the body is a rerank result list.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchesResponse(array $data): bool
    {
        return isset($data['results'][0]['relevance_score']);
    }
}
