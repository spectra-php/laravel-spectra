<?php

declare(strict_types=1);

namespace Spectra\Providers\Google\Streaming;

use Spectra\Providers\Google\GeminiUsage;
use Spectra\Support\Payload;
use Spectra\Support\Tracking\StreamHandler;

/**
 * Reads a streamed Gemini response: `:streamGenerateContent?alt=sse` events
 * or the elements of the streamed JSON array sent without `alt=sse`.
 *
 * Every chunk's `usageMetadata` is cumulative, so the latest one replaces
 * what came before.
 */
class GenerateContentStreaming extends StreamHandler
{
    /**
     * Extract text from Google streaming chunk.
     *
     * {"candidates": [{"content": {"parts": [{"text": "Hello"}]}}]}
     *
     * Thought summaries (`"thought": true` parts) are left out.
     *
     * @param  array<string, mixed>  $data
     */
    public function text(array $data): ?string
    {
        $parts = $data['candidates'][0]['content']['parts'] ?? null;

        if (! is_array($parts)) {
            return null;
        }

        $text = null;

        foreach ($parts as $part) {
            if (is_array($part) && is_string($part['text'] ?? null) && ! ($part['thought'] ?? false)) {
                $text = "{$text}{$part['text']}";
            }
        }

        return $text;
    }

    /**
     * Merge the cumulative usage, service tier and grounding calls of a Gemini chunk into the usage.
     *
     * {"usageMetadata": {"promptTokenCount": 10, "candidatesTokenCount": 20, "thoughtsTokenCount": 5, "serviceTier": "flex"}}
     *
     * Returns the normalised TokenMetrics keys, plus `pricing_tier` when the
     * chunk reports its service tier and `tool_calls` for search grounding.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $currentUsage
     * @return array<string, mixed>
     */
    public function usage(array $data, array $currentUsage): array
    {
        $grounding = GeminiUsage::groundingCalls($data);

        if ($grounding !== []) {
            $toolCalls = is_array($currentUsage['tool_calls'] ?? null) ? $currentUsage['tool_calls'] : [];

            foreach ($grounding as $type => $count) {
                $toolCalls[$type] = max(Payload::int($toolCalls[$type] ?? null), $count);
            }

            $currentUsage['tool_calls'] = $toolCalls;
        }

        if (! is_array($data['usageMetadata'] ?? null)) {
            return $currentUsage;
        }

        $usage = array_replace($currentUsage, GeminiUsage::tokens($data['usageMetadata'])->toUsageArray());
        $tier = GeminiUsage::pricingTier($data['usageMetadata']['serviceTier'] ?? $data['serviceTier'] ?? null);

        if ($tier !== null) {
            $usage['pricing_tier'] = $tier;
        }

        return $usage;
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
     * Extract model and response id from Google streaming chunk.
     *
     * {"modelVersion": "gemini-3.8-flash", "responseId": "mF3vaO..."}
     *
     * @param  array<string, mixed>  $data
     * @return array{model?: string|null, id?: string|null}|null
     */
    public function model(array $data): ?array
    {
        if (isset($data['modelVersion']) || isset($data['responseId'])) {
            return [
                'model' => $data['modelVersion'] ?? null,
                'id' => $data['responseId'] ?? null,
            ];
        }

        return null;
    }
}
