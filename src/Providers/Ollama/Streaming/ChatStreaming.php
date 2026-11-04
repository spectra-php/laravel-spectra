<?php

declare(strict_types=1);

namespace Spectra\Providers\Ollama\Streaming;

use Spectra\Support\Tracking\StreamHandler;

/**
 * Reads Ollama's native streams, which are newline-delimited JSON rather
 * than server-sent events.
 *
 * Usage (`prompt_eval_count` / `eval_count`) only arrives in the last chunk,
 * the one with `done: true`.
 */
class ChatStreaming extends StreamHandler
{
    /** @param  array<string, mixed>  $data */
    public function text(array $data): ?string
    {
        // /api/chat streams message.content; /api/generate streams response.
        return $data['message']['content'] ?? $data['response'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $currentUsage
     * @return array<string, mixed>
     */
    public function usage(array $data, array $currentUsage): array
    {
        // Usage only appears in the final chunk (done: true)
        if (($data['done'] ?? false) !== true) {
            return $currentUsage;
        }

        return [
            'prompt_tokens' => $data['prompt_eval_count'] ?? 0,
            'completion_tokens' => $data['eval_count'] ?? 0,
            'cached_tokens' => 0,
            'reasoning_tokens' => 0,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function finishReason(array $data): ?string
    {
        if (($data['done'] ?? false) === true) {
            return $data['done_reason'] ?? 'stop';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{model?: string|null, id?: string|null}|null
     */
    public function model(array $data): ?array
    {
        if (isset($data['model'])) {
            return ['model' => $data['model']];
        }

        return null;
    }
}
