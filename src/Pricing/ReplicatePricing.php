<?php

declare(strict_types=1);

namespace Spectra\Pricing;

/**
 * Replicate prices, in cents per 1M tokens or per unit.
 *
 * Official models are billed by input and output (tokens, images, seconds of
 * video). Community models are billed by the time they run on their GPU;
 * those entries are approximations from the model page. FLUX 2 Pro bills
 * per run and per input and output megapixel, but Replicate doesn't return
 * the output size, so it is priced as one 1-megapixel image without input
 * images.
 *
 * Source: https://replicate.com/pricing and each model page's billing table
 * (last reviewed 2026-10-15).
 */
class ReplicatePricing extends ProviderPricing
{
    /**
     * Get the provider slug the Replicate prices are recorded under.
     */
    public function provider(): string
    {
        return 'replicate';
    }

    /**
     * Get the Replicate pricing pages `spectra:pricing-scrape` reads.
     */
    public function sources(): array
    {
        return ['https://replicate.com/pricing'];
    }

    /**
     * Register the built-in Replicate models and their prices.
     */
    protected function define(): void
    {
        $this->model('anthropic/claude-sonnet-5', fn ($model) => $model
            ->displayName('Claude Sonnet 5')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 1000));

        // Anthropic retired Claude Sonnet 3.7 on its API on 2026-02-19;
        // the Replicate model page now returns 404.
        $this->model('anthropic/claude-3.7-sonnet', fn ($model) => $model
            ->displayName('Anthropic Claude 3.7 Sonnet')
            ->retiredOn('2026-02-19')
            ->canGenerateText()
            ->tier('standard', inputPrice: 300, outputPrice: 1500));

        $this->model('deepseek-ai/deepseek-r1', fn ($model) => $model
            ->displayName('DeepSeek R1')
            ->canGenerateText()
            ->tier('standard', inputPrice: 375, outputPrice: 1000));

        // Images
        $this->model('black-forest-labs/flux-1.1-pro', fn ($model) => $model
            ->displayName('FLUX 1.1 Pro')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 4));

        // Billed $0.015 per run plus $0.015 per input and per output
        // megapixel. Replicate doesn't return the output size, so this is the
        // price of one 1-megapixel image without input images.
        $this->model('black-forest-labs/flux-2-pro', fn ($model) => $model
            ->displayName('FLUX 2 Pro')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 3));

        $this->model('black-forest-labs/flux-dev', fn ($model) => $model
            ->displayName('FLUX Dev')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 2.5));

        $this->model('black-forest-labs/flux-schnell', fn ($model) => $model
            ->displayName('FLUX Schnell')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 0.3));

        $this->model('ideogram-ai/ideogram-v3-quality', fn ($model) => $model
            ->displayName('Ideogram V3 Quality')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 9));

        $this->model('recraft-ai/recraft-v3', fn ($model) => $model
            ->displayName('Recraft V3')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 4));

        // Community model billed by GPU time ($0.000975/s on an L40S); the
        // model page puts a typical run at about $0.0038. Approximate.
        $this->model('stability-ai/sdxl', fn ($model) => $model
            ->displayName('SDXL')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 0.38));

        // Video, per second of output video
        $this->model('google/veo-3.1-lite', fn ($model) => $model
            ->displayName('Veo 3.1 Lite')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 5)
            ->tier('hd', pricePerUnit: 8));

        $this->model('wavespeedai/wan-2.1-i2v-480p', fn ($model) => $model
            ->displayName('Wan 2.1 I2V 480p')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 9));

        $this->model('wavespeedai/wan-2.1-i2v-720p', fn ($model) => $model
            ->displayName('Wan 2.1 I2V 720p')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 25));
    }
}
