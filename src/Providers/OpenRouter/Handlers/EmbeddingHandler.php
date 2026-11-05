<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenRouter\Handlers;

use Spectra\Concerns\ExtractsModelField;
use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\OpenRouter\Concerns\ReadsReportedCost;
use Spectra\Support\Payload;

/**
 * OpenRouter embeddings (`/api/v1/embeddings`), billed on input tokens. The
 * cost comes from `usage.cost`, like every other OpenRouter response.
 */
class EmbeddingHandler implements ExtractsModelFromResponse, Handler, MatchesResponseShape
{
    use ExtractsModelField;
    use MatchesEndpoints;
    use ReadsReportedCost;

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
        return ['/api/v1/embeddings'];
    }

    /**
     * Get the input tokens and reported cost of an embedding response.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $usage = is_array($responseData['usage'] ?? null) ? $responseData['usage'] : [];

        return new Metrics(
            tokens: new TokenMetrics(
                promptTokens: Payload::int($usage['prompt_tokens'] ?? $usage['total_tokens'] ?? null),
                completionTokens: 0,
            ),
            reportedCostInCents: $this->costFromUsage($usage),
        );
    }

    /**
     * Get a placeholder text naming the embedding count and dimensions.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        $data = $response['data'] ?? null;

        if (! is_array($data) || ! isset($data[0]['embedding'])) {
            return null;
        }

        $first = $data[0]['embedding'];
        $dimensions = is_array($first) ? count($first) : null;
        $count = count($data);

        return $dimensions === null
            ? "[embeddings: {$count}]"
            : "[embeddings: {$count} × {$dimensions} dimensions]";
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
