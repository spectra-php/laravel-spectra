<?php

declare(strict_types=1);

namespace Spectra\Providers\Cerebras\Handlers;

use Spectra\Providers\Concerns\OpenAiCompatibleChatHandler;

/**
 * Cerebras Inference chat completions (OpenAI-compatible). Usage is
 * OpenAI-shaped; the `time_info` block next to it is timing, not billing.
 */
class ChatHandler extends OpenAiCompatibleChatHandler
{
    /**
     * Get the chat completion endpoint paths.
     */
    public function endpoints(): array
    {
        return ['/v1/chat/completions'];
    }
}
