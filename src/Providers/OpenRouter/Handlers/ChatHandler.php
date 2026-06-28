<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenRouter\Handlers;

use Spectra\Providers\Concerns\OpenAiCompatibleChatHandler;

class ChatHandler extends OpenAiCompatibleChatHandler
{
    public function endpoints(): array
    {
        return ['/api/v1/chat/completions'];
    }

    /**
     * OpenRouter responses don't always carry an `object` discriminator, so we
     * match on the presence of the OpenAI-shaped `choices` + `usage` keys.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchesResponse(array $data): bool
    {
        return isset($data['choices'], $data['usage']);
    }
}
