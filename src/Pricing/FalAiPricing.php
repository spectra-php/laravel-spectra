<?php

namespace Spectra\Pricing;

class FalAiPricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'falai';
    }

    protected function define(): void
    {
        $this->model('fal-ai/flux-pro/kontext', fn ($m) => $m
            ->displayName('FLUX Pro Kontext')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 4));

        $this->model('fal-ai/imagen4/preview', fn ($m) => $m
            ->displayName('Imagen 4 Preview')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 4));

        $this->model('fal-ai/flux-pro/v1.1-ultra', fn ($m) => $m
            ->displayName('FLUX Pro v1.1 Ultra')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 6));

        $this->model('fal-ai/recraft/v3/text-to-image', fn ($m) => $m
            ->displayName('Recraft V3 Text-to-Image')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 8));
    }
}
