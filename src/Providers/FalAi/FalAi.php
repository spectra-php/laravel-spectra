<?php

namespace Spectra\Providers\FalAi;

use Spectra\Providers\FalAi\Handlers\ImageHandler;
use Spectra\Providers\Provider;

class FalAi extends Provider
{
    public function getProvider(): string
    {
        return 'falai';
    }

    public function getHosts(): array
    {
        return ['fal.run', 'queue.fal.run'];
    }

    public function handlers(): array
    {
        return [
            app(ImageHandler::class),
        ];
    }
}
