<?php

declare(strict_types=1);

namespace Spectra\Providers\Ollama;

use Spectra\Providers\Ollama\Handlers\ChatHandler;
use Spectra\Providers\Ollama\Handlers\EmbeddingHandler;
use Spectra\Providers\Provider;

/**
 * Ollama, a local model server. Requests are tracked for tokens and latency,
 * but are priced at zero since the models run on the user's own hardware.
 */
class Ollama extends Provider
{
    public function getProvider(): string
    {
        return 'ollama';
    }

    public function getHosts(): array
    {
        return ['localhost:11434', '127.0.0.1:11434'];
    }

    public function handlers(): array
    {
        return [
            app(EmbeddingHandler::class),
            app(ChatHandler::class),
        ];
    }
}
