<?php

declare(strict_types=1);

namespace Spectra\Providers\Anthropic;

use Spectra\Contracts\ExtractsCostMultiplier;
use Spectra\Contracts\ExtractsPricingTierFromRequest;
use Spectra\Contracts\ExtractsPricingTierFromResponse;
use Spectra\Enums\PricingTier;
use Spectra\Providers\Anthropic\Handlers\MessageHandler;
use Spectra\Providers\Provider;

/**
 * Anthropic's Claude API (api.anthropic.com).
 *
 * Besides the handlers it reads the billing modifiers the Messages API
 * reports: the tier (`usage.service_tier`, fast mode in `usage.speed`) and
 * the 1.1x surcharge for US-only inference (`usage.inference_geo`).
 */
class Anthropic extends Provider implements ExtractsCostMultiplier, ExtractsPricingTierFromRequest, ExtractsPricingTierFromResponse
{
    /** Price multiplier for `inference_geo: "us"` (Claude 4.6 and later; the API rejects it on older models). */
    public const US_INFERENCE_MULTIPLIER = 1.1;

    /**
     * Get the provider's slug.
     */
    public function getProvider(): string
    {
        return 'anthropic';
    }

    /**
     * Get the hosts Anthropic's API is served from.
     */
    public function getHosts(): array
    {
        return ['api.anthropic.com'];
    }

    /**
     * Get the handlers for Anthropic's endpoints.
     */
    public function handlers(): array
    {
        return [
            app(MessageHandler::class),
        ];
    }

    /**
     * Get the pricing tier the request asked for.
     *
     * Only used when the response doesn't say, because a response always
     * reports the speed and tier it really got.
     *
     * @param  array<string, mixed>  $requestData
     */
    public function extractPricingTierFromRequest(array $requestData): ?string
    {
        if (($requestData['speed'] ?? null) === 'fast') {
            return PricingTier::Fast->value;
        }

        // `standard_only` keeps the request off Priority Tier capacity.
        if (($requestData['service_tier'] ?? null) === 'standard_only') {
            return PricingTier::Standard->value;
        }

        return null;
    }

    /**
     * Get the pricing tier the response's usage says the request was served at.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function extractPricingTierFromResponse(array $responseData): ?string
    {
        return self::pricingTierFromUsage(self::usageFrom($responseData));
    }

    /**
     * Get the US-only inference surcharge, read from the response usage or the request.
     *
     * @param  array<string, mixed>  $responseData
     * @param  array<string, mixed>  $requestData
     */
    public function extractCostMultiplier(array $responseData, array $requestData): ?float
    {
        $usage = self::usageFrom($responseData);

        // The response reports where inference ran; the request is only a
        // fallback for bodies without usage (e.g. a recorded stream summary).
        if (blank($usage)) {
            return self::costMultiplierForGeo($requestData['inference_geo'] ?? null);
        }

        return self::costMultiplierForGeo($usage['inference_geo'] ?? null);
    }

    /**
     * Get the pricing tier a usage object says the request was served at.
     *
     * Fast mode is reported in `speed`; Priority Tier and batch in
     * `service_tier`. `standard` / `default` mean standard prices, whatever
     * default tier the app configured.
     *
     * @param  array<string, mixed>  $usage
     */
    public static function pricingTierFromUsage(array $usage): ?string
    {
        $speed = $usage['speed'] ?? null;

        if ($speed === 'fast') {
            return PricingTier::Fast->value;
        }

        $tier = match ($usage['service_tier'] ?? null) {
            'priority' => PricingTier::Priority->value,
            'batch' => PricingTier::Batch->value,
            'standard', 'default' => PricingTier::Standard->value,
            default => null,
        };

        if ($tier !== null) {
            return $tier;
        }

        // Fast mode can be silently served at standard speed (Claude Opus
        // 4.6); `speed: standard` then means standard prices.
        return $speed === 'standard' ? PricingTier::Standard->value : null;
    }

    /**
     * Get the price multiplier for an `inference_geo` value.
     */
    public static function costMultiplierForGeo(mixed $inferenceGeo): ?float
    {
        return $inferenceGeo === 'us' ? self::US_INFERENCE_MULTIPLIER : null;
    }

    /**
     * Get the usage object of a message, or of a message inside a batch
     * result line (`result.message.usage`).
     *
     * @param  array<string, mixed>  $responseData
     * @return array<string, mixed>
     */
    protected static function usageFrom(array $responseData): array
    {
        $usage = $responseData['usage']
            ?? $responseData['message']['usage']
            ?? $responseData['result']['message']['usage']
            ?? null;

        return is_array($usage) ? $usage : [];
    }
}
