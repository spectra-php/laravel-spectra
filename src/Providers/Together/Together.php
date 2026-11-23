<?php

declare(strict_types=1);

namespace Spectra\Providers\Together;

use Spectra\Providers\Provider;
use Spectra\Providers\Together\Handlers\ChatHandler;

/**
 * Together AI: serverless inference for open models behind an
 * OpenAI-compatible chat completions API.
 *
 * Only chat completions are tracked. Dedicated endpoints are billed by the
 * hour, not per token, and are not priced.
 */
class Together extends Provider
{
    /**
     * Get the provider's slug.
     */
    public function getProvider(): string
    {
        return 'together';
    }

    /**
     * Get the hosts Together AI's API is served from (`api.together.xyz` is its older name).
     */
    public function getHosts(): array
    {
        return ['api.together.ai', 'api.together.xyz', 'api-inference.together.ai'];
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
