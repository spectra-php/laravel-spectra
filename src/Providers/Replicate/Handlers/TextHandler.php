<?php

declare(strict_types=1);

namespace Spectra\Providers\Replicate\Handlers;

use Spectra\Contracts\ExtractsModelFromRequest;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasFinishReason;
use Spectra\Contracts\HasProviderLatency;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Contracts\ReportsJobFailure;
use Spectra\Contracts\SkipsResponse;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Replicate\Concerns\HandlesPredictions;
use Spectra\Support\Payload;

/**
 * Replicate predictions from language models (Claude, DeepSeek, Llama, ...).
 *
 * Official language models are billed per token and report the counts in
 * `metrics.input_token_count` / `metrics.output_token_count`. The output is
 * a list of text pieces (or one string) that are joined.
 */
class TextHandler implements ExtractsModelFromRequest, ExtractsModelFromResponse, Handler, HasFinishReason, HasProviderLatency, MatchesResponseShape, ReportsJobFailure, SkipsResponse
{
    use HandlesPredictions;

    public function modelType(): ModelType
    {
        return ModelType::Text;
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        return new Metrics(
            tokens: new TokenMetrics(
                promptTokens: Payload::int($responseData['metrics']['input_token_count'] ?? null),
                completionTokens: Payload::int($responseData['metrics']['output_token_count'] ?? null),
            ),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        $output = $response['output'] ?? null;

        if (is_string($output)) {
            return $output;
        }

        // Language models stream their answer as a list of text pieces.
        if (is_array($output) && $output !== [] && is_string($output[0] ?? null)) {
            return implode('', array_filter($output, 'is_string'));
        }

        return null;
    }

    /** @param  array<string, mixed>  $data */
    public function matchesResponse(array $data): bool
    {
        return $this->predictionKind($data) === 'text';
    }
}
