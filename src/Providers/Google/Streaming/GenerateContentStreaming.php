<?php

namespace Spectra\Providers\Google\Streaming;

use Spectra\Support\Tracking\StreamHandler;

class GenerateContentStreaming extends StreamHandler
{
    /**
     * Extract text from Google streaming chunk.
     *
     * {"candidates": [{"content": {"parts": [{"text": "Hello"}]}}]}
     *
     * @param  array<string, mixed>  $data
     */
    public function text(array $data): ?string
    {
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    }

    /**
     * Extract usage from Google streaming chunk.
     *
     * {"usageMetadata": {"promptTokenCount": 10, "candidatesTokenCount": 20, "cachedContentTokenCount": 5}}
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $currentUsage
     * @return array<string, mixed>
     */
    public function usage(array $data, array $currentUsage): array
    {
        if (isset($data['usageMetadata'])) {
            return [
                'prompt_tokens' => $data['usageMetadata']['promptTokenCount'] ?? 0,
                'completion_tokens' => $data['usageMetadata']['candidatesTokenCount'] ?? 0,
                'cached_tokens' => $data['usageMetadata']['cachedContentTokenCount'] ?? 0,
                'reasoning_tokens' => $data['usageMetadata']['thoughtsTokenCount'] ?? 0,
            ];
        }

        return $currentUsage;
    }

    /**
     * Extract finish reason from Google streaming chunk.
     *
     * {"candidates": [{"finishReason": "STOP"}]}
     *
     * @param  array<string, mixed>  $data
     */
    public function finishReason(array $data): ?string
    {
        return $data['candidates'][0]['finishReason'] ?? null;
    }

    /**
     * Extract model from Google streaming chunk.
     *
     * {"modelVersion": "gemini-1.5-pro-001"}
     *
     * @param  array<string, mixed>  $data
     * @return array{model?: string|null, id?: string|null}|null
     */
    public function model(array $data): ?array
    {
        if (isset($data['modelVersion'])) {
            return ['model' => $data['modelVersion']];
        }

        return null;
    }
}
