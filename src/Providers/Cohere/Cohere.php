<?php

declare(strict_types=1);

namespace Spectra\Providers\Cohere;

use Spectra\Providers\Cohere\Handlers\ChatHandler;
use Spectra\Providers\Cohere\Handlers\EmbeddingHandler;
use Spectra\Providers\Cohere\Handlers\ParseHandler;
use Spectra\Providers\Cohere\Handlers\RerankHandler;
use Spectra\Providers\Provider;

/**
 * Cohere: Chat, Embed, Rerank and Parse. The API answers on both
 * `api.cohere.com` and `api.cohere.ai` (the docs use either).
 */
class Cohere extends Provider
{
    /**
     * Get the slug Cohere requests are recorded under.
     */
    public function getProvider(): string
    {
        return 'cohere';
    }

    /**
     * Get the hosts the Cohere API is served from.
     */
    public function getHosts(): array
    {
        return ['api.cohere.com', 'api.cohere.ai'];
    }

    /**
     * Get the handlers for Cohere's chat, embed, rerank and parse endpoints.
     */
    public function handlers(): array
    {
        return [
            app(ChatHandler::class),
            app(EmbeddingHandler::class),
            app(RerankHandler::class),
            app(ParseHandler::class),
        ];
    }
}
