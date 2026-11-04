<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenRouter;

use Spectra\Providers\OpenRouter\Handlers\ChatHandler;
use Spectra\Providers\OpenRouter\Handlers\EmbeddingHandler;
use Spectra\Providers\OpenRouter\Handlers\ImageGenerationHandler;
use Spectra\Providers\OpenRouter\Handlers\ImageHandler;
use Spectra\Providers\OpenRouter\Handlers\RerankHandler;
use Spectra\Providers\OpenRouter\Handlers\VideoHandler;
use Spectra\Providers\Provider;

/**
 * OpenRouter: one OpenAI-compatible API in front of many model providers.
 *
 * Every response reports the cost OpenRouter charged (`usage.cost`), which
 * Spectra records as the exact cost; the pricing catalog is only a fallback
 * for responses without it.
 */
class OpenRouter extends Provider
{
    /**
     * Get the provider's slug.
     */
    public function getProvider(): string
    {
        return 'openrouter';
    }

    /**
     * Get the hosts OpenRouter's API is served from.
     */
    public function getHosts(): array
    {
        return ['openrouter.ai'];
    }

    /**
     * Get the handlers for OpenRouter's endpoints, specialists after the chat handler.
     */
    public function handlers(): array
    {
        // ImageHandler must come after ChatHandler: both match the same
        // endpoint, and specialists are resolved from the end of this list.
        return [
            app(ChatHandler::class),
            app(ImageHandler::class),
            app(ImageGenerationHandler::class),
            app(VideoHandler::class),
            app(EmbeddingHandler::class),
            app(RerankHandler::class),
        ];
    }
}
