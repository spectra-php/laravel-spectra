<?php

declare(strict_types=1);

namespace Spectra\Providers\Cohere\Handlers;

use Spectra\Concerns\ExtractsModelField;
use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;

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
            '/v2/embed',
            '/v1/embed',
        ];
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        // v2 reports billing under meta.billed_units; some responses use usage.billed_units.
        $billed = $responseData['meta']['billed_units']
            ?? $responseData['usage']['billed_units']
            ?? [];

        return new Metrics(
            tokens: new TokenMetrics(
                promptTokens: (int) ($billed['input_tokens'] ?? 0),
                completionTokens: 0,
            ),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        // v2: embeddings.float[][] — v1: embeddings[][]
        $vector = $response['embeddings']['float'][0]
            ?? $response['embeddings'][0]
            ?? null;

        if (! is_array($vector)) {
            return null;
        }

        $dimensions = count($vector);

        return "[embedding: {$dimensions} dimensions]";
    }

    /** @param  array<string, mixed>  $data */
    public function matchesResponse(array $data): bool
    {
        return isset($data['embeddings']) && is_array($data['embeddings']);
    }
}
