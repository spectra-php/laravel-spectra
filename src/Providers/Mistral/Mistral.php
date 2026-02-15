<?php

namespace Spectra\Providers\Mistral;

use Spectra\Providers\Mistral\Handlers\ChatHandler;
use Spectra\Providers\Mistral\Handlers\EmbeddingHandler;
use Spectra\Providers\Provider;

class Mistral extends Provider
{
    public function getProvider(): string
    {
        return 'mistral';
    }

    public function getHosts(): array
    {
        return ['api.mistral.ai', 'codestral.mistral.ai'];
    }

    public function handlers(): array
    {
        return [
            app(EmbeddingHandler::class),
            app(ChatHandler::class),
        ];
    }
}
