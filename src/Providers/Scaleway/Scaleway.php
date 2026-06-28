<?php

declare(strict_types=1);

namespace Spectra\Providers\Scaleway;

use Spectra\Providers\Provider;
use Spectra\Providers\Scaleway\Handlers\ChatHandler;
use Spectra\Providers\Scaleway\Handlers\EmbeddingHandler;
use Spectra\Providers\Scaleway\Handlers\RerankHandler;
use Spectra\Providers\Scaleway\Handlers\TranscriptionHandler;

class Scaleway extends Provider
{
    public function getProvider(): string
    {
        return 'scaleway';
    }

    public function getHosts(): array
    {
        return ['api.scaleway.ai'];
    }

    public function handlers(): array
    {
        return [
            app(EmbeddingHandler::class),
            app(TranscriptionHandler::class),
            app(RerankHandler::class),
            app(ChatHandler::class),
        ];
    }
}
