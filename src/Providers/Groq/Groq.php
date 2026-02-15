<?php

namespace Spectra\Providers\Groq;

use Spectra\Providers\Groq\Handlers\ChatHandler;
use Spectra\Providers\Provider;

class Groq extends Provider
{
    public function getProvider(): string
    {
        return 'groq';
    }

    public function getHosts(): array
    {
        return ['api.groq.com'];
    }

    public function handlers(): array
    {
        return [
            app(ChatHandler::class),
        ];
    }
}
