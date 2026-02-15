<?php

namespace Spectra\Providers\Ollama\Streaming;

use Spectra\Support\Tracking\StreamHandler;

class ChatStreaming extends StreamHandler
{
    /** @param  array<string, mixed>  $data */
    public function text(array $data): ?string
    {
        // /api/chat format: message.content
        if (isset($data['message']['content'])) {
            return $data['message']['content'];
        }

        // /api/generate format: response
        if (isset($data['response'])) {
            return $data['response'];
        }

        return null;
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
