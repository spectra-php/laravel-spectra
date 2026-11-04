<?php

declare(strict_types=1);

namespace Spectra\Providers\Scaleway;

use Spectra\Providers\Provider;
use Spectra\Providers\Scaleway\Handlers\ChatHandler;
use Spectra\Providers\Scaleway\Handlers\EmbeddingHandler;
use Spectra\Providers\Scaleway\Handlers\RerankHandler;
use Spectra\Providers\Scaleway\Handlers\TranscriptionHandler;

/**
 * Scaleway Generative APIs (serverless), an OpenAI-compatible API hosted in
 * Europe at `api.scaleway.ai`, priced in euros.
 *
 * Dedicated deployments run on their own hosts and are not tracked.
 */
class Scaleway extends Provider
{
    /**
     * Get the slug Scaleway requests are recorded under.
     */
    public function getProvider(): string
    {
        return 'scaleway';
    }

    /**
     * Get the host the Scaleway Generative APIs are served from.
     */
    public function getHosts(): array
    {
        return ['api.scaleway.ai'];
    }

    /**
     * Get the handlers for Scaleway embeddings, transcription, rerank and chat.
     */
    public function handlers(): array
    {
        return [
            app(EmbeddingHandler::class),
            app(TranscriptionHandler::class),
            app(RerankHandler::class),
            app(ChatHandler::class),
        ];
    }
}
