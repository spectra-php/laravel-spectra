<?php

namespace Spectra\Providers\OpenRouter;

use Spectra\Providers\OpenRouter\Handlers\ChatHandler;
use Spectra\Providers\OpenRouter\Handlers\ImageHandler;
use Spectra\Providers\Provider;

class OpenRouter extends Provider
{
    public function getProvider(): string
    {
        return 'openrouter';
    }

    public function getHosts(): array
    {
        return ['openrouter.ai'];
    }

    public function handlers(): array
    {
        return [
            app(ImageHandler::class),
            app(ChatHandler::class),
        ];
    }
}
