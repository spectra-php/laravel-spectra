<?php

declare(strict_types=1);

namespace Spectra\Providers\Mistral\Streaming;

use Spectra\Providers\Concerns\OpenAiCompatibleStreaming;
use Spectra\Providers\Mistral\Handlers\ChatHandler;

/**
 * Mistral chat stream.
 *
 * While a reasoning model thinks, `delta.content` is a list holding a
 * `thinking` chunk; the chunk that ends the thinking also carries the first
 * `text` chunk, and after that `delta.content` is a plain string. Only the
 * answer text is collected.
 */
class ChatStreaming extends OpenAiCompatibleStreaming
{
    /**
     * Get the answer text of a Mistral stream chunk, leaving out thinking chunks.
     *
     * @param  array<string, mixed>  $data
     */
    public function text(array $data): ?string
    {
        return ChatHandler::answerText($data['choices'][0]['delta']['content'] ?? null);
    }
}
