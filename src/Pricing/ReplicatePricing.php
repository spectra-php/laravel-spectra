<?php

namespace Spectra\Pricing;

class ReplicatePricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'replicate';
    }

    protected function define(): void
    {
        $this->model('anthropic/claude-3.7-sonnet', fn ($m) => $m
            ->displayName('Anthropic Claude 3.7 Sonnet')
            ->canGenerateText()
            ->tier('standard', inputPrice: 300, outputPrice: 1500));

        $this->model('deepseek-ai/deepseek-r1', fn ($m) => $m
            ->displayName('DeepSeek R1')
            ->canGenerateText()
            ->tier('standard', inputPrice: 375, outputPrice: 1000));

        // Images
        $this->model('black-forest-labs/flux-1.1-pro', fn ($m) => $m
            ->displayName('FLUX 1.1 Pro')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 4));

        $this->model('black-forest-labs/flux-dev', fn ($m) => $m
            ->displayName('FLUX Dev')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 2.5));

        $this->model('black-forest-labs/flux-schnell', fn ($m) => $m
            ->displayName('FLUX Schnell')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 0.3));

        $this->model('ideogram-ai/ideogram-v3-quality', fn ($m) => $m
            ->displayName('Ideogram V3 Quality')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 9));

        $this->model('recraft-ai/recraft-v3', fn ($m) => $m
            ->displayName('Recraft V3')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 4));

        // Video
        $this->model('wavespeedai/wan-2.1-i2v-480p', fn ($m) => $m
            ->displayName('Wan 2.1 I2V 480p')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 9));

        $this->model('wavespeedai/wan-2.1-i2v-720p', fn ($m) => $m
            ->displayName('Wan 2.1 I2V 720p')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 25));

        $this->model('stability-ai/sdxl', fn ($m) => $m
            ->displayName('SDXL')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 0.41));
    }
}
