<?php

declare(strict_types=1);

namespace Spectra\Providers\Scaleway\Handlers;

use Spectra\Concerns\ExtractsModelField;
use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;

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
        return ['/{project_id}/v1/rerank'];
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        return new Metrics(
            tokens: new TokenMetrics(
                promptTokens: (int) ($responseData['usage']['prompt_tokens'] ?? $responseData['usage']['total_tokens'] ?? 0),
                completionTokens: 0,
            ),
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
