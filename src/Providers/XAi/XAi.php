<?php

declare(strict_types=1);

namespace Spectra\Providers\XAi;

use Spectra\Contracts\ExtractsPricingTierFromRequest;
use Spectra\Contracts\ExtractsPricingTierFromResponse;
use Spectra\Enums\PricingTier;
use Spectra\Providers\Provider;
use Spectra\Providers\XAi\Handlers\ChatHandler;
use Spectra\Providers\XAi\Handlers\ImageHandler;
use Spectra\Providers\XAi\Handlers\ResponsesHandler;
use Spectra\Providers\XAi\Handlers\VideoHandler;

/**
 * xAI (Grok) API: chat completions, the Responses API, and Grok Imagine
 * image and video generation.
 *
 * `us.api.x.ai` is the US regional endpoint. Its 1.1x surcharge is included
 * in the `cost_in_usd_ticks` every response reports.
 */
class XAi extends Provider implements ExtractsPricingTierFromRequest, ExtractsPricingTierFromResponse
{
    /**
     * Get the slug xAI requests are recorded under.
     */
    public function getProvider(): string
    {
        return 'xai';
    }

    /**
     * Get the global and US regional hosts of the xAI API.
     */
    public function getHosts(): array
    {
        return ['api.x.ai', 'us.api.x.ai'];
    }

    /**
     * Each handler has distinct endpoints — no overlap.
     */
    public function handlers(): array
    {
        return [
            app(ImageHandler::class),
            app(VideoHandler::class),
            app(ChatHandler::class),
            app(ResponsesHandler::class),
        ];
    }

    /**
     * Used only when the response does not say which tier served it.
     *
     * @param  array<string, mixed>  $requestData
     */
    public function extractPricingTierFromRequest(array $requestData): ?string
    {
        return $this->tierFromServiceTier($requestData['service_tier'] ?? null);
    }

    /**
     * xAI bills the priority rate only when the response confirms
     * `service_tier: priority` (`fast` is the same tier under another name).
     *
     * @param  array<string, mixed>  $responseData
     */
    public function extractPricingTierFromResponse(array $responseData): ?string
    {
        return $this->tierFromServiceTier($responseData['service_tier'] ?? null);
    }

    /**
     * Polled video results carry no id; the request id is the last path
     * segment (`/v1/videos/{request_id}`), and deferred image results return
     * it as `request_id`.
     */
    public function extractResponseId(mixed $response, string $endpoint = ''): ?string
    {
        $data = $this->toArray($response);

        foreach (['id', 'request_id'] as $key) {
            if (filled($data[$key] ?? null)) {
                return $data[$key];
            }
        }

        if (preg_match('#^/v1/videos/(?!generations$|edits$|extensions$)([^/]+)$#', $endpoint, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Map an xAI `service_tier` value to a Spectra pricing tier.
     */
    protected function tierFromServiceTier(mixed $serviceTier): ?string
    {
        return match ($serviceTier) {
            'priority', 'fast' => PricingTier::Priority->value,
            'default' => PricingTier::Standard->value,
            default => null,
        };
    }
}
