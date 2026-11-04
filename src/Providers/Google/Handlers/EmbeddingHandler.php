<?php

declare(strict_types=1);

namespace Spectra\Providers\Google\Handlers;

use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Contracts\ExtractsModelFromRequest;
use Spectra\Contracts\Handler;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Google\ExtractsModelFromGoogleEndpoint;
use Spectra\Providers\Google\GoogleTokenCounter;
use Spectra\Support\Payload;

/**
 * Gemini embeddings (`embedContent`, `batchEmbedContents`). The response has
 * no usage, so the prompt size comes from the countTokens API.
 */
class EmbeddingHandler implements ExtractsModelFromRequest, Handler, MatchesResponseShape
{
    use ExtractsModelFromGoogleEndpoint;
    use MatchesParametricEndpoints;

    /**
     * Create a new Gemini embedding handler instance.
     */
    public function __construct(
        protected GoogleTokenCounter $tokenCounter
    ) {}

    /**
     * Get the model type this handler records.
     */
    public function modelType(): ModelType
    {
        return ModelType::Embedding;
    }

    /**
     * Get the single and batch embedding endpoint paths.
     */
    public function endpoints(): array
    {
        return [
            '/{version}/models/{model}:embedContent',
            '/{version}/models/{model}:batchEmbedContents',
        ];
    }

    /**
     * Get the prompt tokens of an embedding, from usage or the countTokens API.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        // The embedContent response does not include usageMetadata.
        // When available (future-proofing), use it; otherwise call countTokens API.
        $promptTokens = Payload::int($responseData['usageMetadata']['promptTokenCount'] ?? null);

        if ($promptTokens === 0) {
            $promptTokens = $this->countTokensViaApi($requestData) ?? 0;
        }

        return new Metrics(
            tokens: new TokenMetrics(
                promptTokens: $promptTokens,
            ),
        );
    }

    /**
     * Call Google's countTokens API to get the exact prompt token count.
     *
     * The model name is injected into requestData by the Google provider
     * (as `_spectra_model`) since the embedContent request body doesn't
     * include it — it's only in the URL path.
     *
     * @param  array<string, mixed>  $requestData
     */
    protected function countTokensViaApi(array $requestData): ?int
    {
        $model = $requestData['_spectra_model'] ?? null;

        if (! $model) {
            return null;
        }

        // Single embedContent: { "content": { "parts": [...] } }
        if (isset($requestData['content']['parts'])) {
            return $this->tokenCounter->count($model, $requestData['content']['parts']);
        }

        // batchEmbedContents: { "requests": [{ "content": { "parts": [...] } }] }
        if (is_array($requestData['requests'] ?? null)) {
            $batchParts = [];
            foreach ($requestData['requests'] as $request) {
                $batchParts[] = $request['content']['parts'] ?? [];
            }

            return $this->tokenCounter->countBatch($model, $batchParts);
        }

        return null;
    }

    /**
     * Get a placeholder text naming the embedding's dimensions or batch size.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        if (isset($response['embedding']['values'])) {
            $dimensions = count($response['embedding']['values']);

            return "[embedding: {$dimensions} dimensions]";
        }

        if (isset($response['embeddings'])) {
            $count = count($response['embeddings']);

            return "[batch embeddings: {$count} items]";
        }

        return null;
    }

    /**
     * Determine if the body is a single or batch embedding response.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchesResponse(array $data): bool
    {
        return isset($data['embedding']['values']) || isset($data['embeddings']);
    }
}
