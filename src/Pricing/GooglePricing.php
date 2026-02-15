<?php

namespace Spectra\Pricing;

class GooglePricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'google';
    }

    protected function define(): void
    {
        $this->model('gemini-3-pro-preview', fn ($m) => $m
            ->displayName('Gemini 3 Pro Preview')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 1200, cachedInputPrice: 20)
            ->tier('batch', inputPrice: 100, outputPrice: 600, cachedInputPrice: 20));

        $this->model('gemini-3-flash-preview', fn ($m) => $m
            ->displayName('Gemini 3 Flash Preview')
            ->canGenerateText()
            ->tier('standard', inputPrice: 50, outputPrice: 300, cachedInputPrice: 5)
            ->tier('batch', inputPrice: 25, outputPrice: 150, cachedInputPrice: 5));

        $this->model('gemini-2.5-pro', fn ($m) => $m
            ->displayName('Gemini 2.5 Pro')
            ->canGenerateText()
            ->tier('standard', inputPrice: 125, outputPrice: 1000, cachedInputPrice: 12.5)
            ->tier('batch', inputPrice: 62.5, outputPrice: 500, cachedInputPrice: 12.5));

        $this->model('gemini-2.5-flash', fn ($m) => $m
            ->displayName('Gemini 2.5 Flash')
            ->canGenerateText()
            ->tier('standard', inputPrice: 30, outputPrice: 250, cachedInputPrice: 3)
            ->tier('batch', inputPrice: 15, outputPrice: 125, cachedInputPrice: 3));

        $this->model('gemini-2.5-flash-lite', fn ($m) => $m
            ->displayName('Gemini 2.5 Flash-Lite')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 40, cachedInputPrice: 1)
            ->tier('batch', inputPrice: 5, outputPrice: 20, cachedInputPrice: 1));

        $this->model('gemini-2.0-flash', fn ($m) => $m
            ->displayName('Gemini 2.0 Flash')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 40, cachedInputPrice: 2.5)
            ->tier('batch', inputPrice: 5, outputPrice: 20, cachedInputPrice: 2.5));

        $this->model('gemini-2.0-flash-lite', fn ($m) => $m
            ->displayName('Gemini 2.0 Flash-Lite')
            ->canGenerateText()
            ->tier('standard', inputPrice: 7.5, outputPrice: 30)
            ->tier('batch', inputPrice: 3.75, outputPrice: 15));

        // Audio / TTS
        $this->model('gemini-2.5-flash-preview-tts', fn ($m) => $m
            ->displayName('Gemini 2.5 Flash TTS')
            ->type('audio')
            ->canGenerateAudio()
            ->tier('standard', inputPrice: 50, outputPrice: 1000)
            ->tier('batch', inputPrice: 25, outputPrice: 500));

        $this->model('gemini-2.5-pro-preview-tts', fn ($m) => $m
            ->displayName('Gemini 2.5 Pro TTS')
            ->type('audio')
            ->canGenerateAudio()
            ->tier('standard', inputPrice: 100, outputPrice: 2000)
            ->tier('batch', inputPrice: 50, outputPrice: 1000));

        // Image
        $this->model('gemini-3-pro-image-preview', fn ($m) => $m
            ->displayName('Gemini 3 Pro Image Preview')
            ->type('image')
            ->canGenerateText()
            ->canGenerateImages()
            ->tier('standard', inputPrice: 200, outputPrice: 1200)
            ->tier('batch', inputPrice: 100, outputPrice: 600));

        $this->model('gemini-2.5-flash-image', fn ($m) => $m
            ->displayName('Gemini 2.5 Flash Image')
            ->type('image')
            ->canGenerateText()
            ->canGenerateImages()
            ->tier('standard', inputPrice: 30, outputPrice: 3000)
            ->tier('batch', inputPrice: 15, outputPrice: 1500));

        // Robotics
        $this->model('gemini-robotics-er-1.5-preview', fn ($m) => $m
            ->displayName('Gemini Robotics-ER 1.5 Preview')
            ->canGenerateText()
            ->tier('standard', inputPrice: 30, outputPrice: 250));

        // Computer Use
        $this->model('gemini-2.5-computer-use-preview-10-2025', fn ($m) => $m
            ->displayName('Gemini 2.5 Computer Use Preview')
            ->canGenerateText()
            ->tier('standard', inputPrice: 125, outputPrice: 1000));

        // Embedding
        $this->model('gemini-embedding-001', fn ($m) => $m
            ->displayName('Gemini Embedding')
            ->type('embedding')
            ->tier('standard', inputPrice: 15, outputPrice: 0)
            ->tier('batch', inputPrice: 7.5, outputPrice: 0));

        // Veo — Video Generation (pricePerUnit in cents per second)
        $this->model('veo-2.0-generate-001', fn ($m) => $m
            ->displayName('Veo 2')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 35));

        $this->model('veo-3.0-generate-001', fn ($m) => $m
            ->displayName('Veo 3')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 40));

        $this->model('veo-3.0-fast-generate-001', fn ($m) => $m
            ->displayName('Veo 3 Fast')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 15));

        $this->model('veo-3.1-generate-001', fn ($m) => $m
            ->displayName('Veo 3.1')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 40));

        $this->model('veo-3.1-fast-generate-001', fn ($m) => $m
            ->displayName('Veo 3.1 Fast')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 15));
    }
}
