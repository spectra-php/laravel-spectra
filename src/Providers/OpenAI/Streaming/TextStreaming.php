<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenAI\Streaming;

use Illuminate\Support\Str;
use Spectra\Providers\OpenAI\Handlers\TextHandler;
use Spectra\Providers\OpenAI\OpenAI;
use Spectra\Providers\OpenAI\OpenAiUsage;
use Spectra\Support\Tracking\StreamHandler;

/**
 * Reads OpenAI text streams: Chat Completions chunks and Responses API events.
 *
 * Chat Completions only send usage in a last chunk when the request sets
 * `stream_options.include_usage`; without it the stream carries no token
 * counts and is recorded with zero tokens. A Responses stream ends with one
 * of three terminal events (`response.completed`, `response.incomplete`,
 * `response.failed`), each carrying the final response and its usage.
 */
class TextStreaming extends StreamHandler
{
    /** Responses API events that carry the final response object. */
    public const TERMINAL_EVENTS = ['response.completed', 'response.incomplete', 'response.failed'];

    /**
     * Extract text from OpenAI streaming chunk.
     *
     * Completions API: {"choices": [{"delta": {"content": "Hello"}}]}
     * Legacy completions: {"object": "text_completion", "choices": [{"text": "Hello"}]}
     * Responses API:   {"type": "response.output_text.delta", "delta": "Hello"}
     *                  {"type": "content.delta", "delta": {"text": "Hello"}}
     *
     * @param  array<string, mixed>  $data
     */
    public function text(array $data): ?string
    {
        // Completions API format
        if (isset($data['choices'][0]['delta']['content'])) {
            return $data['choices'][0]['delta']['content'];
        }

        // Legacy completions stream text, not deltas
        if (($data['object'] ?? null) === 'text_completion' && is_string($data['choices'][0]['text'] ?? null)) {
            return $data['choices'][0]['text'];
        }

        // Responses API format
        if (isset($data['type']) && $data['type'] === 'response.output_text.delta') {
            return $data['delta'] ?? null;
        }

        // Responses API content part
        if (isset($data['type']) && $data['type'] === 'content.delta') {
            return $data['delta']['text'] ?? null;
        }

        return null;
    }

    /**
     * Extract usage (and the served pricing tier) from a streaming chunk.
     *
     * Completions API (with stream_options.include_usage), last chunk:
     *   {"choices": [], "service_tier": "default", "usage": {"prompt_tokens": 10, ...}}
     *
     * Responses API, terminal event:
     *   {"type": "response.completed", "response": {"service_tier": "default", "usage": {"input_tokens": 10, ...}}}
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $currentUsage
     * @return array<string, mixed>
     */
    public function usage(array $data, array $currentUsage): array
    {
        $type = $data['type'] ?? null;

        if ($type !== null) {
            if (! in_array($type, self::TERMINAL_EVENTS, true) || ! is_array($data['response'] ?? null)) {
                return $currentUsage;
            }

            $response = $data['response'];

            if (is_array($response['usage'] ?? null)) {
                $currentUsage = array_merge($currentUsage, OpenAiUsage::fromResponses($response['usage'])->toUsageArray());
            }

            return $this->withPricingTier($currentUsage, $response['service_tier'] ?? null);
        }

        // Completions API — no 'type' key. Every chunk repeats the service tier.
        if (is_array($data['usage'] ?? null)) {
            $currentUsage = array_merge($currentUsage, OpenAiUsage::fromChatCompletions($data['usage'])->toUsageArray());
        }

        return $this->withPricingTier($currentUsage, $data['service_tier'] ?? null);
    }

    /**
     * Extract finish reason from OpenAI streaming chunk.
     *
     * Completions API: {"choices": [{"finish_reason": "stop"}]}
     * Responses API:   {"type": "response.incomplete", "response": {"status": "incomplete", "incomplete_details": {"reason": "max_output_tokens"}}}
     *
     * @param  array<string, mixed>  $data
     */
    public function finishReason(array $data): ?string
    {
        // Completions API
        if (isset($data['choices'][0]['finish_reason'])) {
            return $data['choices'][0]['finish_reason'];
        }

        // Responses API
        $type = $data['type'] ?? null;

        if (in_array($type, self::TERMINAL_EVENTS, true)) {
            $response = is_array($data['response'] ?? null) ? $data['response'] : [];

            return TextHandler::responseFinishReason($response) ?? Str::after($type, 'response.');
        }

        return null;
    }

    /**
     * Extract model and response ID from OpenAI streaming chunk.
     *
     * Completions chunks carry both on every chunk; Responses events carry
     * them in the nested `response` of `response.created`, `.in_progress`
     * and the terminal events.
     *
     * @param  array<string, mixed>  $data
     * @return array{model?: string|null, id?: string|null}|null
     */
    public function model(array $data): ?array
    {
        if (isset($data['type'])) {
            $response = $data['response'] ?? null;

            if (! is_array($response)) {
                return null;
            }

            $result = collect([
                'model' => is_string($response['model'] ?? null) ? $response['model'] : null,
                'id' => is_string($response['id'] ?? null) ? $response['id'] : null,
            ])->filter()->all();

            return filled($result) ? $result : null;
        }

        $result = collect([
            'model' => is_string($data['model'] ?? null) ? $data['model'] : null,
            'id' => is_string($data['id'] ?? null) ? $data['id'] : null,
        ])->filter()->all();

        return filled($result) ? $result : null;
    }

    /**
     * Add the pricing tier a service tier maps to, when it maps to one.
     *
     * @param  array<string, mixed>  $usage
     * @return array<string, mixed>
     */
    protected function withPricingTier(array $usage, mixed $serviceTier): array
    {
        $tier = OpenAI::pricingTierFor($serviceTier);

        if ($tier !== null) {
            $usage['pricing_tier'] = $tier;
        }

        return $usage;
    }
}
