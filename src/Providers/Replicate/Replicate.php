<?php

namespace Spectra\Providers\Replicate;

use Spectra\Providers\Provider;
use Spectra\Providers\Replicate\Handlers\ImageHandler;
use Spectra\Providers\Replicate\Handlers\TextHandler;
use Spectra\Providers\Replicate\Handlers\VideoHandler;

class Replicate extends Provider
{
    public function getProvider(): string
    {
        return 'replicate';
    }

    public function getHosts(): array
    {
        return ['api.replicate.com'];
    }

    /**
     * Order matters: all handlers share the same endpoint pattern,
     * so disambiguation relies on MatchesResponseShape (checked in reverse order).
     * VideoHandler is checked first (single URL), then TextHandler, then ImageHandler.
     */
    public function handlers(): array
    {
        return [
            app(ImageHandler::class),
            app(TextHandler::class),
            app(VideoHandler::class),
        ];
    }
}
