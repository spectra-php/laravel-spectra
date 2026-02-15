<?php

namespace Spectra\Providers\OpenAI\Handlers;

use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasFinishReason;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Contracts\StreamsResponse;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\OpenAI\Streaming\TextStreaming;
use Spectra\Support\Tracking\StreamHandler;

class TextHandler implements Handler, HasFinishReason, MatchesResponseShape, StreamsResponse
{
    use MatchesEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Text;
    }

    public function endpoints(): array
    {
        return [
            '/v1/chat/completions',
            '/v1/completions',
            '/v1/responses',
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
                cachedTokens: (int) ($responseData['usage']['prompt_tokens_details']['cached_tokens'] ?? $responseData['usage']['input_tokens_details']['cached_tokens'] ?? 0),
                reasoningTokens: (int) ($responseData['usage']['completion_tokens_details']['reasoning_tokens'] ?? $responseData['usage']['output_tokens_details']['reasoning_tokens'] ?? 0),
            ),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractModel(array $response): ?string
    {
        return $response['model'] ?? null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        // Chat completions: choices[*].message.content
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

        // Responses API: output[*].content[*].text
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
        return self::isResponsesApi($data) || self::isCompletionsApi($data);
    }

    /** @param  array<string, mixed>  $data */
    public static function isResponsesApi(array $data): bool
    {
        return ($data['object'] ?? null) === 'response';
    }

    /** @param  array<string, mixed>  $data */
    public static function isCompletionsApi(array $data): bool
    {
        return in_array($data['object'] ?? null, ['chat.completion', 'text_completion'], true);
    }

    /** @param  array<string, mixed>  $data */
    public static function isBatch(array $data): bool
    {
        return isset($data['custom_id'], $data['response']['body']);
    }

    /**
     * Unwrap a batch response to its inner body, or return the original data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function unwrapBatch(array $data): array
    {
        if (self::isBatch($data)) {
            return $data['response']['body'] ?? [];
        }

        return $data;
    }

    public function streamingHandler(): StreamHandler
    {
        return new TextStreaming;
    }
}
