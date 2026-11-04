<?php

declare(strict_types=1);

namespace Spectra\Providers\Groq\Handlers;

use Spectra\Providers\Concerns\OpenAiCompatibleChatHandler;

/**
 * Groq chat completions (OpenAI-compatible).
 *
 * Streams report usage in `x_groq.usage` on the last chunk (and in `usage`
 * when `stream_options.include_usage` is set); the shared base handler reads
 * both.
 */
class ChatHandler extends OpenAiCompatibleChatHandler
{
    /**
     * Get the chat completion endpoint paths.
     */
    public function endpoints(): array
    {
        return ['/openai/v1/chat/completions'];
    }
}
