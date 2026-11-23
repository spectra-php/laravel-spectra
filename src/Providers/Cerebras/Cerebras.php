<?php

declare(strict_types=1);

namespace Spectra\Providers\Cerebras;

use Spectra\Providers\Cerebras\Handlers\ChatHandler;
use Spectra\Providers\Provider;

/**
 * Cerebras Inference: an OpenAI-compatible chat completions API for the
 * models Cerebras hosts on its own chips.
 *
 * Only chat completions are tracked.
 */
class Cerebras extends Provider
{
    /**
     * Get the provider's slug.
     */
    public function getProvider(): string
    {
        return 'cerebras';
    }

    /**
     * Get the host the Cerebras Inference API is served from.
     */
    public function getHosts(): array
    {
        return ['api.cerebras.ai'];
    }

    /**
     * Get the handler for chat completions.
     */
    public function handlers(): array
    {
        return [
            app(ChatHandler::class),
        ];
    }
}
