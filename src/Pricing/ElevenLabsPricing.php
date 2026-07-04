<?php

declare(strict_types=1);

namespace Spectra\Pricing;

/**
 * ElevenLabs bills subscription plans in credits; the prices below are the
 * published pay-as-you-go API rates (USD per character), which approximate
 * what a request costs. Override this class if your plan's effective rate
 * differs.
 *
 * Source: https://elevenlabs.io/pricing/api (last reviewed 2026-07-04)
 * - Flash / Turbo models: $0.05 per 1,000 characters
 * - Multilingual v2/v3 models: $0.10 per 1,000 characters
 */
class ElevenLabsPricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'elevenlabs';
    }

    protected function define(): void
    {
        // Prices are cents per 1M characters.
        $this->model('eleven_v3', fn ($m) => $m
            ->displayName('Eleven v3')
            ->type('audio')
            ->pricingUnit('characters')
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: 10000));

        $this->model('eleven_multilingual_v2', fn ($m) => $m
            ->displayName('Eleven Multilingual v2')
            ->type('audio')
            ->pricingUnit('characters')
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: 10000));

        $this->model('eleven_flash_v2_5', fn ($m) => $m
            ->displayName('Eleven Flash v2.5')
            ->type('audio')
            ->pricingUnit('characters')
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: 5000));

        $this->model('eleven_flash_v2', fn ($m) => $m
            ->displayName('Eleven Flash v2')
            ->type('audio')
            ->pricingUnit('characters')
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: 5000));

        $this->model('eleven_turbo_v2_5', fn ($m) => $m
            ->displayName('Eleven Turbo v2.5')
            ->type('audio')
            ->pricingUnit('characters')
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: 5000));

        $this->model('eleven_turbo_v2', fn ($m) => $m
            ->displayName('Eleven Turbo v2')
            ->type('audio')
            ->pricingUnit('characters')
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: 5000));
    }
}
