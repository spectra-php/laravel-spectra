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

    /** @param  array<string, mixed>  $responseData */
    public function resolveHandler(string $endpoint, array $responseData = []): ?Handler
    {
        return parent::resolveHandler($endpoint, $this->normalise($responseData));
    }

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
     * `serviceTier` on the request body (`service_tier` is accepted too, as
     * the API takes both spellings). For Veo, a 1080p video is the `hd` tier
     * where the model prices it differently.
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
        return is_string($resolution) && strtolower($resolution) === '1080p' ? PricingTier::Hd->value : null;
    }

    /** @param  array<string, mixed>  $responseData */
    public function extractPricingTierFromResponse(array $responseData): ?string
    {
        $data = $this->normalise($responseData);

        return GeminiUsage::pricingTier($data['usageMetadata']['serviceTier'] ?? $data['serviceTier'] ?? null);
    }

    /** @return array<string, mixed> */
    protected function toArray(mixed $response): array
    {
        return $this->normalise(parent::toArray($response));
    }

    /**
     * @param  array<mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalise(array $data): array
    {
        return GeminiUsage::isChunkList($data) ? GeminiUsage::mergeChunks($data) : $data;
    }
}
