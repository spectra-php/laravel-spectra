<?php

declare(strict_types=1);

namespace Spectra\Providers\Fireworks;

use Spectra\Providers\Fireworks\Handlers\ChatHandler;
use Spectra\Providers\Provider;

/**
 * Fireworks AI: serverless inference for open models behind an
 * OpenAI-compatible chat completions API under `/inference/v1/`.
 *
 * Only chat completions are tracked. On-demand deployments are billed by GPU
 * time, not per token, and are not priced.
 */
class Fireworks extends Provider
{
    /**
     * Get the provider's slug.
     */
    public function getProvider(): string
    {
        return 'fireworks';
    }

    /**
     * Get the host Fireworks AI's API is served from.
     */
    public function getHosts(): array
    {
        return ['api.fireworks.ai'];
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
