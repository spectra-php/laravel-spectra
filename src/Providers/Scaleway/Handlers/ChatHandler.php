<?php

namespace Spectra\Providers\Scaleway\Handlers;

use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasFinishReason;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;

class ChatHandler implements ExtractsModelFromResponse, Handler, HasFinishReason, MatchesResponseShape
{
    use MatchesParametricEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Text;
    }

    public function endpoints(): array
    {
        return [
            '/{project_id}/v1/chat/completions',
            '/{project_id}/v1/responses',
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
                promptTokens: (int) ($responseData['usage']['prompt_tokens'] ?? $responseData['usage']['input_tokens'] ?? 0),
                completionTokens: (int) ($responseData['usage']['completion_tokens'] ?? $responseData['usage']['output_tokens'] ?? 0),
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
        if (isset($response['choices']) && is_array($response['choices'])) {
            $contents = [];

            foreach ($response['choices'] as $choice) {
                $content = $choice['message']['content'] ?? $choice['text'] ?? null;

                if ($content !== null && $content !== '') {
                    $contents[] = $content;
                }
            }

            if (! empty($contents)) {
                return implode("\n", $contents);
            }
        }

        if (isset($response['output']) && is_array($response['output'])) {
            $texts = [];

            foreach ($response['output'] as $outputItem) {
                if (isset($outputItem['content']) && is_array($outputItem['content'])) {
                    foreach ($outputItem['content'] as $contentItem) {
                        if (isset($contentItem['text']) && $contentItem['text'] !== '') {
                            $texts[] = $contentItem['text'];
                        }
                    }
                }
            }

            if (! empty($texts)) {
                return implode("\n", $texts);
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractFinishReason(array $response): ?string
    {
        return $response['choices'][0]['finish_reason'] ?? $response['status'] ?? null;
    }

    /** @param  array<string, mixed>  $data */
    public function matchesResponse(array $data): bool
    {
        $object = $data['object'] ?? null;

        return in_array($object, ['chat.completion', 'response'], true);
    }
}
