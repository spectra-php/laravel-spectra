<?php

namespace Spectra\Providers\Anthropic\Streaming;

use Spectra\Support\Tracking\StreamHandler;

class MessageStreaming extends StreamHandler
{
    /**
     * Extract text from Anthropic streaming chunk.
     *
     * {"type": "content_block_delta", "delta": {"type": "text_delta", "text": "Hello"}}
     *
     * @param  array<string, mixed>  $data
     */
    public function text(array $data): ?string
    {
        if (($data['type'] ?? null) === 'content_block_delta') {
            return $data['delta']['text'] ?? null;
        }

        return null;
    }

    /**
     * Extract usage from Anthropic streaming chunk.
     *
     * Anthropic splits usage across two events:
     *   message_start: {"message": {"usage": {"input_tokens": 10, "cache_read_input_tokens": 5}}}
     *   message_delta:  {"usage": {"output_tokens": 20}}
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $currentUsage
     * @return array<string, mixed>
     */
    public function usage(array $data, array $currentUsage): array
    {
        // message_start — input tokens
        if (($data['type'] ?? null) === 'message_start' && isset($data['message']['usage'])) {
            $currentUsage['prompt_tokens'] = $data['message']['usage']['input_tokens'] ?? 0;
            $currentUsage['cached_tokens'] = $data['message']['usage']['cache_read_input_tokens'] ?? 0;
            $currentUsage['cache_creation_tokens'] = $data['message']['usage']['cache_creation_input_tokens'] ?? 0;

            return $currentUsage;
        }

        // message_delta — output tokens
        if (($data['type'] ?? null) === 'message_delta' && isset($data['usage'])) {
            $currentUsage['completion_tokens'] = $data['usage']['output_tokens'] ?? 0;

            return $currentUsage;
        }

        return $currentUsage;
    }

    /**
     * Extract finish reason from Anthropic streaming chunk.
     *
     * The real stop reason comes from `message_delta` with `delta.stop_reason`.
     * `message_stop` is just an end-of-stream signal, not a stop reason.
     *
     * @param  array<string, mixed>  $data
     */
    public function finishReason(array $data): ?string
    {
        if (($data['type'] ?? null) === 'message_delta' && isset($data['delta']['stop_reason'])) {
            return $data['delta']['stop_reason'];
        }

        return null;
    }

    /**
     * Extract model and response ID from Anthropic streaming chunk.
     *
     * message_start: {"message": {"model": "claude-3-5-sonnet-...", "id": "msg_..."}}
     *
     * @param  array<string, mixed>  $data
     * @return array{model?: string|null, id?: string|null}|null
     */
    public function model(array $data): ?array
    {
        if (($data['type'] ?? null) === 'message_start') {
            return [
                'model' => $data['message']['model'] ?? null,
                'id' => $data['message']['id'] ?? null,
            ];
        }

        return null;
    }
}
