<?php

namespace Spectra\Providers\Ollama;

use Spectra\Providers\Ollama\Handlers\ChatHandler;
use Spectra\Providers\Ollama\Handlers\EmbeddingHandler;
use Spectra\Providers\Provider;

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
