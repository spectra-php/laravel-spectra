<?php

declare(strict_types=1);

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
        // ImageHandler must come after ChatHandler: both match the same
        // endpoint, and specialists are resolved from the end of this list.
        return [
            app(ChatHandler::class),
            app(ImageHandler::class),
        ];
    }
}
