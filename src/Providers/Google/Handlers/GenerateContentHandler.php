<?php

declare(strict_types=1);

namespace Spectra\Providers\Google\Handlers;

use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Contracts\ExtractsModelFromRequest;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasFinishReason;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Contracts\StreamsResponse;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Google\ExtractsModelFromGoogleEndpoint;
use Spectra\Providers\Google\GeminiUsage;
use Spectra\Providers\Google\Streaming\GenerateContentStreaming;
use Spectra\Support\Tracking\StreamHandler;

/**
 * Gemini `generateContent` / `streamGenerateContent` text responses (the
 * default handler for those endpoints; image and audio responses go to the
 * specialist handlers).
 */
class GenerateContentHandler implements ExtractsModelFromRequest, ExtractsModelFromResponse, Handler, HasFinishReason, MatchesResponseShape, StreamsResponse
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
     * Thinking and tool-use prompt tokens are reported beside the prompt and
     * candidate counts and billed too; GeminiUsage folds them in.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        return new Metrics(
            tokens: GeminiUsage::tokens(GeminiUsage::metadata($responseData)),
            toolCalls: GeminiUsage::groundingCalls($responseData),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractModelFromResponse(array $response): ?string
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
            // Thought summaries are not part of the answer.
            if (filled($part['text'] ?? null) && ! ($part['thought'] ?? false)) {
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
