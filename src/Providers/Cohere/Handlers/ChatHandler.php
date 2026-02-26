<?php

namespace Spectra\Providers\Cohere\Handlers;

use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasFinishReason;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;

class ChatHandler implements ExtractsModelFromResponse, Handler, HasFinishReason, MatchesResponseShape
{
    use MatchesEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Text;
    }

    public function endpoints(): array
    {
        return ['/v2/chat'];
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $billed = $responseData['usage']['billed_units'] ?? [];

        return new Metrics(
            tokens: new TokenMetrics(
                promptTokens: (int) ($billed['input_tokens'] ?? 0),
                completionTokens: (int) ($billed['output_tokens'] ?? 0),
            ),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractModelFromResponse(array $response): ?string
    {
        return $response['model'] ?? null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        if (! isset($response['message']['content']) || ! is_array($response['message']['content'])) {
            return null;
        }

        $texts = [];

        foreach ($response['message']['content'] as $block) {
            if (($block['type'] ?? null) === 'text' && isset($block['text']) && $block['text'] !== '') {
                $texts[] = $block['text'];
            }
        }

        return ! empty($texts) ? implode("\n", $texts) : null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractFinishReason(array $response): ?string
    {
        return $response['finish_reason'] ?? null;
    }

    /** @param  array<string, mixed>  $data */
    public function matchesResponse(array $data): bool
    {
        return isset($data['message'], $data['finish_reason']);
    }
}
