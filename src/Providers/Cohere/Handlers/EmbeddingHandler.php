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
use Spectra\Support\Payload;

/**
 * Cohere Embed (`/v2/embed`, `/v1/embed`), billed per input token.
 *
 * Image inputs (Embed v4 and newer) are billed as `billed_units.image_tokens`,
 * separate from the text `input_tokens`. They are counted as image input
 * tokens; no separate image price is published, so they bill at the model's
 * input price.
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

        $imageTokens = Payload::int($billed['image_tokens'] ?? null);

        return new Metrics(
            tokens: new TokenMetrics(
                promptTokens: Payload::int($billed['input_tokens'] ?? null) + $imageTokens,
                completionTokens: 0,
                imageInputTokens: $imageTokens,
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
        return is_array($data['embeddings'] ?? null);
    }
}
