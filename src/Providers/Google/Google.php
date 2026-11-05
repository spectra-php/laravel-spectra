<?php

declare(strict_types=1);

namespace Spectra\Providers\Google;

use Illuminate\Support\Str;
use Spectra\Contracts\ExtractsPricingTierFromRequest;
use Spectra\Contracts\ExtractsPricingTierFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Data\Metrics;
use Spectra\Enums\PricingTier;
use Spectra\Providers\Google\Handlers\EmbeddingHandler;
use Spectra\Providers\Google\Handlers\GenerateContentHandler;
use Spectra\Providers\Google\Handlers\ImageHandler;
use Spectra\Providers\Google\Handlers\TtsHandler;
use Spectra\Providers\Google\Handlers\VideoHandler;
use Spectra\Providers\Provider;

/**
 * The Gemini Developer API (generativelanguage.googleapis.com).
 *
 * Reads the service tier (`serviceTier`: standard, flex or priority) from the
 * request and from `usageMetadata.serviceTier` in the response. A response
 * that is the whole body of a `:streamGenerateContent` call without
 * `alt=sse` (a JSON array of chunks) is folded into one response first.
 */
class Google extends Provider implements ExtractsPricingTierFromRequest, ExtractsPricingTierFromResponse
{
    use ExtractsModelFromGoogleEndpoint;

    /**
     * Get the provider's slug.
     */
    public function getProvider(): string
    {
        return 'google';
    }

    /**
     * Get the hosts the Gemini API is served from.
     */
    public function getHosts(): array
    {
        return ['generativelanguage.googleapis.com'];
    }

    /**
     * Get the Gemini handlers, with the response-shape specialists after the generic handler.
     *
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
            app(VideoHandler::class),           // Veo: matched by endpoint (predictLongRunning, operation polls)
        ];
    }

    /**
     * Inject the model name (from the URL path) into requestData so the
     * EmbeddingHandler can use it to call the countTokens API.
     *
     * @param  array<string, mixed>  $requestData
     */
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

    /**
     * Resolve the handler for an endpoint, folding a streamed chunk list first.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function resolveHandler(string $endpoint, array $responseData = []): ?Handler
    {
        return parent::resolveHandler($endpoint, $this->normalise($responseData));
    }

    /**
     * Get the Veo operation name or `responseId` that identifies the response.
     */
    public function extractResponseId(mixed $response, string $endpoint = ''): ?string
    {
        $data = $this->toArray($response);

        // A Veo job is identified by its operation name, from the submit
        // response (running, no `done` yet) to the poll that finishes it, so
        // the submit's request is remembered for that poll and repeated polls
        // of the same job update one row.
        $operation = $data['name'] ?? null;

        if (filled($operation) && Str::contains($operation, '/operations/')) {
            return $operation;
        }

        return filled($data['responseId'] ?? null)
            ? $data['responseId']
            : parent::extractResponseId($response, $endpoint);
    }

    /**
     * Get the pricing tier from the request body's `serviceTier` or video resolution.
     *
     * `service_tier` is accepted too, as the API takes both spellings. For
     * Veo, a 1080p video is the `hd` tier where the model prices it
     * differently.
     *
     * @param  array<string, mixed>  $requestData
     */
    public function extractPricingTierFromRequest(array $requestData): ?string
    {
        $tier = GeminiUsage::pricingTier($requestData['serviceTier'] ?? $requestData['service_tier'] ?? null);

        if ($tier !== null) {
            return $tier;
        }

        $resolution = $requestData['parameters']['resolution']
            ?? $requestData['instances'][0]['parameters']['resolution']
            ?? null;

        // 4K has no tier of its own yet; it is left at the base price.
        return is_string($resolution) && Str::lower($resolution) === '1080p' ? PricingTier::Hd->value : null;
    }

    /**
     * Get the pricing tier from the response's `usageMetadata.serviceTier`.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function extractPricingTierFromResponse(array $responseData): ?string
    {
        $data = $this->normalise($responseData);

        return GeminiUsage::pricingTier($data['usageMetadata']['serviceTier'] ?? $data['serviceTier'] ?? null);
    }

    /**
     * Convert the response to an array, folding a streamed chunk list into one response.
     *
     * @return array<string, mixed>
     */
    protected function toArray(mixed $response): array
    {
        return $this->normalise(parent::toArray($response));
    }

    /**
     * Fold a streamed chunk list into one response, or return the body unchanged.
     *
     * @param  array<mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalise(array $data): array
    {
        return GeminiUsage::isChunkList($data) ? GeminiUsage::mergeChunks($data) : $data;
    }
}
