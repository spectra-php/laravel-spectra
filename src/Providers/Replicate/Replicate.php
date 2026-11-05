<?php

declare(strict_types=1);

namespace Spectra\Providers\Replicate;

use Illuminate\Support\Str;
use Spectra\Contracts\ExtractsPricingTierFromResponse;
use Spectra\Enums\PricingTier;
use Spectra\Providers\Provider;
use Spectra\Providers\Replicate\Handlers\ImageHandler;
use Spectra\Providers\Replicate\Handlers\TextHandler;
use Spectra\Providers\Replicate\Handlers\VideoHandler;
use Spectra\Support\Pricing\PricingLookup;

/**
 * Replicate predictions: official models, community versions and
 * deployments, created and polled through the same prediction object.
 */
class Replicate extends Provider implements ExtractsPricingTierFromResponse
{
    /**
     * Get the slug Replicate requests are recorded under.
     */
    public function getProvider(): string
    {
        return 'replicate';
    }

    /**
     * Get the hosts the Replicate API is served from.
     */
    public function getHosts(): array
    {
        return ['api.replicate.com'];
    }

    /**
     * Get the handlers for Replicate image, text and video predictions.
     *
     * All handlers share the prediction endpoints; the response decides
     * (model type, output files, token counts). ImageHandler comes first,
     * so it takes predictions nobody else claims.
     */
    public function handlers(): array
    {
        return [
            app(ImageHandler::class),
            app(TextHandler::class),
            app(VideoHandler::class),
        ];
    }

    /**
     * Get the `hd` pricing tier for a 1080p prediction of a model with an `hd` price.
     *
     * Video models such as Veo 3.1 Lite charge more for 1080p output. The
     * prediction echoes its input, so the resolution is known even for
     * polls. Only models with an `hd` price get the `hd` tier.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function extractPricingTierFromResponse(array $responseData): ?string
    {
        $resolution = $responseData['input']['resolution'] ?? null;
        $model = $responseData['model'] ?? null;

        if (! is_string($resolution) || Str::lower($resolution) !== '1080p' || ! is_string($model)) {
            return null;
        }

        $tiers = app(PricingLookup::class)->getModelData('replicate', $model)['tiers'] ?? [];

        return isset($tiers[PricingTier::Hd->value]) ? PricingTier::Hd->value : null;
    }
}
