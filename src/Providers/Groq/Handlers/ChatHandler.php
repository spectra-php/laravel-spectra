<?php

declare(strict_types=1);

namespace Spectra\Providers\Groq\Handlers;

use Spectra\Providers\Concerns\OpenAiCompatibleChatHandler;

class ChatHandler extends OpenAiCompatibleChatHandler
{
    public function endpoints(): array
    {
        return ['/openai/v1/chat/completions'];
    }
}
