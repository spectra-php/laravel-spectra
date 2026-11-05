<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenAI;

use Illuminate\Support\Str;
use Spectra\Contracts\ExtractsPricingTierFromRequest;
use Spectra\Contracts\ExtractsPricingTierFromResponse;
use Spectra\Enums\PricingTier;
use Spectra\Providers\OpenAI\Handlers\BackgroundResponseHandler;
use Spectra\Providers\OpenAI\Handlers\EmbeddingHandler;
use Spectra\Providers\OpenAI\Handlers\ImageHandler;
use Spectra\Providers\OpenAI\Handlers\ResponsesImageHandler;
use Spectra\Providers\OpenAI\Handlers\SpeechHandler;
use Spectra\Providers\OpenAI\Handlers\TextHandler;
use Spectra\Providers\OpenAI\Handlers\TranscriptionHandler;
use Spectra\Providers\OpenAI\Handlers\VideoHandler;
use Spectra\Providers\Provider;

/**
 * OpenAI provider — thin coordinator that delegates all extraction to handlers.
 *
 * Each handler knows its endpoints, response shapes, and how to extract
 * metrics/content for its capability type (text, images, embeddings, audio).
 * The provider itself maps OpenAI's `service_tier` to Spectra pricing tiers.
 */
class OpenAI extends Provider implements ExtractsPricingTierFromRequest, ExtractsPricingTierFromResponse
{
    /**
     * Get the provider's slug.
     */
    public function getProvider(): string
    {
        return 'openai';
    }

    /**
     * Get the global host, the regional (data residency) hosts, and the Azure
     * resource hosts.
     *
     * Azure requests are priced at OpenAI's rates.
     *
     * Regional endpoints add a 10% uplift for models released on or after
     * 2026-03-05; the uplift is not applied yet because the request host is
     * not available when the cost is calculated.
     */
    public function getHosts(): array
    {
        return [
            'api.openai.com',
            'us.api.openai.com',
            'eu.api.openai.com',
            'au.api.openai.com',
            'ca.api.openai.com',
            'jp.api.openai.com',
            'in.api.openai.com',
            'sg.api.openai.com',
            'kr.api.openai.com',
            'gb.api.openai.com',
            'ae.api.openai.com',
            // Azure OpenAI and Azure AI Foundry resources
            '{resource}.openai.azure.com',
            '{resource}.cognitiveservices.azure.com',
            '{resource}.services.ai.azure.com',
        ];
    }

    /**
     * Map Azure OpenAI paths onto the OpenAI /v1 endpoints.
     *
     *   /openai/deployments/{deployment}/chat/completions => /v1/chat/completions
     *   /openai/v1/responses                              => /v1/responses
     *   /openai/responses (preview API)                   => /v1/responses
     */
    public function normalizeEndpoint(string $path): string
    {
        if (! Str::startsWith($path, '/openai/')) {
            return $path;
        }

        return (string) Str::replaceMatches('#^/openai(?:/deployments/[^/]+|/v1)?/#', '/v1/', $path);
    }

    /**
     * Get the Azure deployment name of a `/openai/deployments/{deployment}/`
     * path. Deployments are usually named after their model, and an image or
     * speech response names none, so the deployment is the only model name
     * such a call has.
     */
    public function extractDeployment(string $path): ?string
    {
        $deployment = Str::match('#^/openai/deployments/([^/]+)/#', $path);

        return filled($deployment) ? rawurldecode($deployment) : null;
    }

    /**
     * Get the handlers for OpenAI's endpoints, in resolution order.
     *
     * Order matters:
     * - TextHandler comes first because a stream without a known endpoint
     *   (a bare Spectra::stream()) uses the first handler that streams.
     * - When several handlers match the same endpoint, specialists
     *   (MatchesResponseShape) are checked in reverse order, so they come
     *   after the default handler they override.
     */
    public function handlers(): array
    {
        return [
            app(TextHandler::class),
            app(ImageHandler::class),
            app(VideoHandler::class),
            app(EmbeddingHandler::class),
            app(TranscriptionHandler::class),
            app(SpeechHandler::class),
            app(ResponsesImageHandler::class), // Specialist: overrides TextHandler for /v1/responses with image output
            app(BackgroundResponseHandler::class),
        ];
    }

    /**
     * Get the pricing tier the request asked for.
     *
     * Only used when the response doesn't say which tier served it.
     *
     * @param  array<string, mixed>  $requestData
     */
    public function extractPricingTierFromRequest(array $requestData): ?string
    {
        $serviceTier = self::pricingTierFor($requestData['service_tier'] ?? null);

        if ($serviceTier !== null) {
            return $serviceTier;
        }

        // DALL-E 3 bills HD images at a higher per-image price.
        if (($requestData['quality'] ?? null) === 'hd') {
            return PricingTier::Hd->value;
        }

        return null;
    }

    /**
     * Get the pricing tier from the response's `service_tier`.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function extractPricingTierFromResponse(array $responseData): ?string
    {
        return self::pricingTierFor($responseData['service_tier'] ?? null);
    }

    /**
     * Map an OpenAI `service_tier` value to a Spectra pricing tier.
     *
     * OpenAI renamed Priority processing to Fast mode on 2026-07-30 and
     * accepts both names; GPT-5.6 and earlier models still answer `priority`.
     * The catalog keeps Fast prices under the `priority` tier. `scale` (Scale
     * Tier) is prepaid capacity billed at standard token rates.
     */
    public static function pricingTierFor(mixed $serviceTier): ?string
    {
        return match ($serviceTier) {
            'default', 'auto', 'scale', 'standard' => PricingTier::Standard->value,
            'flex' => PricingTier::Flex->value,
            'priority', 'fast' => PricingTier::Priority->value,
            'ultrafast' => PricingTier::Ultrafast->value,
            'batch' => PricingTier::Batch->value,
            default => null,
        };
    }
}
