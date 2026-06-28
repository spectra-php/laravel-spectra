<?php

declare(strict_types=1);

namespace Spectra\Providers\XAi\Handlers;

use Spectra\Providers\Concerns\OpenAiCompatibleChatHandler;

class ChatHandler extends OpenAiCompatibleChatHandler
{
    public function endpoints(): array
    {
        return ['/v1/chat/completions'];
    }
}
