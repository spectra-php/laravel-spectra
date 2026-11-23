<?php

declare(strict_types=1);

namespace Spectra\Providers\DeepInfra;

use Spectra\Contracts\ReportsRequestCost;
use Spectra\Providers\DeepInfra\Handlers\ChatHandler;
use Spectra\Providers\Provider;

/**
 * DeepInfra: serverless inference for open models behind an
 * OpenAI-compatible chat completions API under `/v1/openai/`.
 *
 * DeepInfra reports what a request cost in `usage.estimated_cost`, and
 * Spectra records that cost. The catalog prices are the fallback for a
 * response without it.
 */
class DeepInfra extends Provider implements ReportsRequestCost
{
    /**
     * Get the provider's slug.
     */
    public function getProvider(): string
    {
        return 'deepinfra';
    }

    /**
     * Get the host DeepInfra's API is served from.
     */
    public function getHosts(): array
    {
        return ['api.deepinfra.com'];
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
