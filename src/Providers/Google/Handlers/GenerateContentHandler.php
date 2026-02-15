<?php

namespace Spectra\Providers\Google\Handlers;

use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Contracts\ExtractsModelFromRequest;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasFinishReason;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Contracts\StreamsResponse;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Google\ExtractsModelFromGoogleEndpoint;
use Spectra\Providers\Google\Streaming\GenerateContentStreaming;
use Spectra\Support\Tracking\StreamHandler;

class GenerateContentHandler implements ExtractsModelFromRequest, Handler, HasFinishReason, MatchesResponseShape, StreamsResponse
{
    use ExtractsModelFromGoogleEndpoint;
    use MatchesParametricEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Text;
    }

    public function endpoints(): array
    {
        return [
            '/{version}/models/{model}:generateContent',
            '/{version}/models/{model}:streamGenerateContent',
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
                promptTokens: (int) ($responseData['usageMetadata']['promptTokenCount'] ?? 0),
                completionTokens: (int) ($responseData['usageMetadata']['candidatesTokenCount'] ?? 0),
                cachedTokens: (int) ($responseData['usageMetadata']['cachedContentTokenCount'] ?? 0),
                reasoningTokens: (int) ($responseData['usageMetadata']['thoughtsTokenCount'] ?? 0),
            ),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractModel(array $response): ?string
    {
        return $response['modelVersion'] ?? null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        if (! isset($response['candidates'][0]['content']['parts']) || ! is_array($response['candidates'][0]['content']['parts'])) {
            return null;
        }

        $texts = [];

        foreach ($response['candidates'][0]['content']['parts'] as $part) {
            if (isset($part['text']) && $part['text'] !== '') {
                $texts[] = $part['text'];
            }
        }

        if (empty($texts)) {
            return null;
        }

        return count($texts) === 1 ? $texts[0] : implode("\n", $texts);
    }

    /** @param  array<string, mixed>  $response */
    public function extractFinishReason(array $response): ?string
    {
        return $response['candidates'][0]['finishReason'] ?? null;
    }

    /** @param  array<string, mixed>  $data */
    public function matchesResponse(array $data): bool
    {
        return isset($data['candidates']) || isset($data['usageMetadata']);
    }

    public function streamingHandler(): StreamHandler
    {
        return new GenerateContentStreaming;
    }
}
