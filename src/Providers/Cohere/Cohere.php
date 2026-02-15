<?php

namespace Spectra\Providers\Cohere;

use Spectra\Providers\Cohere\Handlers\ChatHandler;
use Spectra\Providers\Provider;

class Cohere extends Provider
{
    public function getProvider(): string
    {
        return 'cohere';
    }

    public function getHosts(): array
    {
        return ['api.cohere.com'];
    }

    public function handlers(): array
    {
        return [
            app(ChatHandler::class),
        ];
    }
}
