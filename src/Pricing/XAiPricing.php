<?php

namespace Spectra\Pricing;

class XAiPricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'xai';
    }

    protected function define(): void
    {
        $this->model('grok-4-1-fast-reasoning', fn ($m) => $m
            ->displayName('Grok 4.1 Fast Reasoning')
            ->canGenerateText()
            ->tier('standard', inputPrice: 20, outputPrice: 50));

        $this->model('grok-4-1-fast-non-reasoning', fn ($m) => $m
            ->displayName('Grok 4.1 Fast Non-Reasoning')
            ->canGenerateText()
            ->tier('standard', inputPrice: 20, outputPrice: 50));

        $this->model('grok-code-fast-1', fn ($m) => $m
            ->displayName('Grok Code Fast 1')
            ->canGenerateText()
            ->tier('standard', inputPrice: 20, outputPrice: 150));

        $this->model('grok-4-fast-reasoning', fn ($m) => $m
            ->displayName('Grok 4 Fast Reasoning')
            ->canGenerateText()
            ->tier('standard', inputPrice: 20, outputPrice: 50));

        $this->model('grok-4-fast-non-reasoning', fn ($m) => $m
            ->displayName('Grok 4 Fast Non-Reasoning')
            ->canGenerateText()
            ->tier('standard', inputPrice: 20, outputPrice: 50));

        $this->model('grok-4-0709', fn ($m) => $m
            ->displayName('Grok 4 0709')
            ->canGenerateText()
            ->tier('standard', inputPrice: 300, outputPrice: 1500));

        $this->model('grok-3-mini', fn ($m) => $m
            ->displayName('Grok 3 Mini')
            ->canGenerateText()
            ->tier('standard', inputPrice: 30, outputPrice: 50));

        $this->model('grok-3', fn ($m) => $m
            ->displayName('Grok 3')
            ->canGenerateText()
            ->tier('standard', inputPrice: 300, outputPrice: 1500));

        $this->model('grok-2-vision-1212', fn ($m) => $m
            ->displayName('Grok 2 Vision 1212')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 1000));

        // Images
        $this->model('grok-imagine-image-pro', fn ($m) => $m
            ->displayName('Grok Imagine Image Pro')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 7));

        $this->model('grok-imagine-image', fn ($m) => $m
            ->displayName('Grok Imagine Image')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 2));

        $this->model('grok-2-image-1212', fn ($m) => $m
            ->displayName('Grok 2 Image 1212')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 7));

        // Video
        $this->model('grok-imagine-video', fn ($m) => $m
            ->displayName('Grok Imagine Video')
            ->type('video')
            ->pricingUnit('minute')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 300));
    }
}
