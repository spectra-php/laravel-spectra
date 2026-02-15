<?php

namespace Spectra\Providers\Google;

use Spectra\Data\Metrics;
use Spectra\Providers\Google\Handlers\EmbeddingHandler;
use Spectra\Providers\Google\Handlers\GenerateContentHandler;
use Spectra\Providers\Google\Handlers\ImageHandler;
use Spectra\Providers\Google\Handlers\TtsHandler;
use Spectra\Providers\Google\Handlers\VideoHandler;
use Spectra\Providers\Provider;

class Google extends Provider
{
    use ExtractsModelFromGoogleEndpoint;

    public function getProvider(): string
    {
        return 'google';
    }

    public function getHosts(): array
    {
        return ['generativelanguage.googleapis.com'];
    }

    /**
     * Order matters: when multiple handlers match the same endpoint,
     * specialists (MatchesResponseShape) are checked in reverse order.
     * GenerateContentHandler is the default fallback for shared endpoints.
     */
    public function handlers(): array
    {
        return [
            app(EmbeddingHandler::class),
            app(GenerateContentHandler::class), // Default: handles all other generateContent responses
            app(ImageHandler::class),           // Specialist: matched by response shape (inline image data)
            app(TtsHandler::class),             // Specialist: matched by response shape (inline audio data)
            app(VideoHandler::class),           // Veo: matched by endpoint (predictLongRunning)
        ];
    }

    /**
     * Override to inject the model name (from the URL path) into requestData
     * so the EmbeddingHandler can use it to call the countTokens API.
     */
    /** @param  array<string, mixed>  $requestData */
    public function extractMetrics(mixed $response, string $endpoint = '', array $requestData = []): Metrics
    {
        $model = $this->extractModelFromRequest([], $endpoint);

        if ($model !== null) {
            $requestData['_spectra_model'] = $model;
        }

        $handler = $this->resolveHandlerFor($endpoint, $response);

        if ($handler === null) {
            return new Metrics;
        }

        return $handler->extractMetrics($requestData, $this->toArray($response));
    }

    public function extractResponseId(mixed $response, string $endpoint = ''): ?string
    {
        return $this->toArray($response)['responseId'] ?? parent::extractResponseId($response, $endpoint);
    }
}
