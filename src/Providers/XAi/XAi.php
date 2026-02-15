<?php

namespace Spectra\Providers\XAi;

use Spectra\Providers\Provider;
use Spectra\Providers\XAi\Handlers\ChatHandler;
use Spectra\Providers\XAi\Handlers\ImageHandler;
use Spectra\Providers\XAi\Handlers\VideoHandler;

class XAi extends Provider
{
    public function getProvider(): string
    {
        return 'xai';
    }

    public function getHosts(): array
    {
        return ['api.x.ai'];
    }

    /**
     * Each handler has distinct endpoints — no overlap.
     */
    public function handlers(): array
    {
        return [
            app(ImageHandler::class),
            app(VideoHandler::class),
            app(ChatHandler::class),
        ];
    }
}
