<?php

declare(strict_types=1);

namespace Spectra\Providers\Fireworks\Handlers;

use Spectra\Providers\Concerns\OpenAiCompatibleChatHandler;

/**
 * Fireworks AI chat completions (OpenAI-compatible), served under
 * `/inference/v1/`. Usage is OpenAI-shaped, with cached prompt tokens in
 * `prompt_tokens_details.cached_tokens`.
 */
class ChatHandler extends OpenAiCompatibleChatHandler
{
    /**
     * Get the chat completion endpoint paths.
     */
    public function endpoints(): array
    {
        return ['/inference/v1/chat/completions'];
    }
}
