<?php

namespace Spectra\Providers\OpenAI\Streaming;

use Spectra\Support\Tracking\StreamHandler;

class TextStreaming extends StreamHandler
{
    /**
     * Extract text from OpenAI streaming chunk.
     *
     * Completions API: {"choices": [{"delta": {"content": "Hello"}}]}
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
     * Extract usage from OpenAI streaming chunk.
     *
     * Completions API (with stream_options.include_usage):
     *   {"usage": {"prompt_tokens": 10, "completion_tokens": 20}}
     *
     * Responses API:
     *   {"type": "response.completed", "response": {"usage": {"input_tokens": 10, "output_tokens": 20}}}
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $currentUsage
     * @return array<string, mixed>
     */
    public function usage(array $data, array $currentUsage): array
    {
        // Completions API (with stream_options.include_usage) — no 'type' key
        if (isset($data['usage']) && ! isset($data['type'])) {
            $usage = $data['usage'];

            return [
                'prompt_tokens' => $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0,
                'cached_tokens' => $usage['prompt_tokens_details']['cached_tokens']
                    ?? $usage['input_tokens_details']['cached_tokens']
                    ?? 0,
                'reasoning_tokens' => $usage['completion_tokens_details']['reasoning_tokens']
                    ?? $usage['output_tokens_details']['reasoning_tokens']
                    ?? 0,
            ];
        }

        // Responses API
        if (($data['type'] ?? null) === 'response.completed' && isset($data['response']['usage'])) {
            $usage = $data['response']['usage'];

            return [
                'prompt_tokens' => $usage['input_tokens'] ?? 0,
                'completion_tokens' => $usage['output_tokens'] ?? 0,
                'cached_tokens' => $usage['input_tokens_details']['cached_tokens'] ?? 0,
                'reasoning_tokens' => $usage['output_tokens_details']['reasoning_tokens'] ?? 0,
            ];
        }

        return $currentUsage;
    }

    /**
     * Extract finish reason from OpenAI streaming chunk.
     *
     * Completions API: {"choices": [{"finish_reason": "stop"}]}
     * Responses API:   {"type": "response.completed", "response": {"status": "completed"}}
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
        if (($data['type'] ?? null) === 'response.completed') {
            return $data['response']['status'] ?? 'completed';
        }

        return null;
    }

    /**
     * Extract model and response ID from OpenAI streaming chunk.
     *
     * @param  array<string, mixed>  $data
     * @return array{model?: string|null, id?: string|null}|null
     */
    public function model(array $data): ?array
    {
        $result = [];

        // Completions API — model and id on every chunk
        if (isset($data['model'])) {
            $result['model'] = $data['model'];
        }

        if (isset($data['id']) && is_string($data['id'])) {
            $result['id'] = $data['id'];
        }

        // Responses API — nested under response.completed
        if (($data['type'] ?? null) === 'response.completed') {
            $result['model'] = $result['model'] ?? ($data['response']['model'] ?? null);
            $result['id'] = $result['id'] ?? ($data['response']['id'] ?? null);
        }

        return ! empty($result) ? $result : null;
    }
}
