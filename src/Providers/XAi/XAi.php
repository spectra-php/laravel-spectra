<?php

declare(strict_types=1);

namespace Spectra\Providers\XAi;

use Illuminate\Support\Str;
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
     * Get the handlers for xAI image, video, chat and Responses requests.
     *
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
     * Get the pricing tier the request's `service_tier` asked for.
     *
     * Used only when the response does not say which tier served it.
     *
     * @param  array<string, mixed>  $requestData
     */
    public function extractPricingTierFromRequest(array $requestData): ?string
    {
        return $this->tierFromServiceTier($requestData['service_tier'] ?? null);
    }

    /**
     * Get the pricing tier the response's `service_tier` reports.
     *
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
     * Get the response id from `id` or `request_id`, or from a video poll's path.
     *
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

        $videoRequestId = Str::match('#^/v1/videos/(?!generations$|edits$|extensions$)([^/]+)$#', $endpoint);

        return filled($videoRequestId) ? $videoRequestId : null;
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
