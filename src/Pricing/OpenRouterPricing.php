<?php

namespace Spectra\Pricing;

class OpenRouterPricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'openrouter';
    }

    protected function define(): void
    {
        $this->model('moonshotai/kimi-k2.5', fn ($m) => $m
            ->displayName('Kimi K2.5')
            ->canGenerateText()
            ->tier('standard', inputPrice: 60, outputPrice: 250));

        $this->model('google/gemini-2.5-flash-lite', fn ($m) => $m
            ->displayName('Gemini 2.5 Flash Lite')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 40));

        $this->model('deepseek/deepseek-v3.2-exp', fn ($m) => $m
            ->displayName('DeepSeek V3.2 Exp')
            ->canGenerateText()
            ->tier('standard', inputPrice: 27, outputPrice: 110));

        $this->model('openai/gpt-5-mini', fn ($m) => $m
            ->displayName('GPT-5 Mini')
            ->canGenerateText()
            ->tier('standard', inputPrice: 25, outputPrice: 200));

        $this->model('google/gemini-2.5-flash', fn ($m) => $m
            ->displayName('Gemini 2.5 Flash')
            ->canGenerateText()
            ->tier('standard', inputPrice: 30, outputPrice: 250));

        $this->model('openai/gpt-5', fn ($m) => $m
            ->displayName('GPT-5')
            ->canGenerateText()
            ->tier('standard', inputPrice: 125, outputPrice: 1000));

        $this->model('anthropic/claude-sonnet-4.5', fn ($m) => $m
            ->displayName('Claude Sonnet 4.5')
            ->canGenerateText()
            ->tier('standard', inputPrice: 300, outputPrice: 1500));

        $this->model('mistralai/devstral-small-2507', fn ($m) => $m
            ->displayName('Devstral Small 2507')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 30));

        $this->model('openai/gpt-5-chat', fn ($m) => $m
            ->displayName('GPT-5 Chat')
            ->canGenerateText()
            ->tier('standard', inputPrice: 125, outputPrice: 1000));

        $this->model('qwen/qwen3-coder', fn ($m) => $m
            ->displayName('Qwen 3 Coder')
            ->canGenerateText()
            ->tier('standard', inputPrice: 20, outputPrice: 80));

        // Images
        $this->model('google/gemini-2.5-flash-image-preview', fn ($m) => $m
            ->displayName('Gemini 2.5 Flash Image Preview')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateText()
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 3));

        $this->model('google/gemini-2.5-flash-image-preview:free', fn ($m) => $m
            ->displayName('Gemini 2.5 Flash Image Preview Free')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateText()
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 0));

        $this->model('black-forest-labs/flux-kontext-max', fn ($m) => $m
            ->displayName('Flux Kontext Max')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 4));

        $this->model('openai/gpt-image-1', fn ($m) => $m
            ->displayName('GPT Image 1')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 1));

        $this->model('google/gemini-2.5-flash-image-preview:thinking', fn ($m) => $m
            ->displayName('Gemini 2.5 Flash Image Preview Thinking')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateText()
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 4));

        // Video
        $this->model('google/veo-3', fn ($m) => $m
            ->displayName('Veo 3')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 75));

        $this->model('minimax/video-01', fn ($m) => $m
            ->displayName('MiniMax Video 01')
            ->type('video')
            ->pricingUnit('video')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 50));

        $this->model('bytedance/seedance-1-lite', fn ($m) => $m
            ->displayName('Seedance 1 Lite')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 10));

        // Audio
        $this->model('minimax/speech-02-hd', fn ($m) => $m
            ->displayName('MiniMax Speech 02 HD')
            ->type('audio')
            ->pricingUnit('characters')
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: 800));

        $this->model('minimax/speech-02-turbo', fn ($m) => $m
            ->displayName('MiniMax Speech 02 Turbo')
            ->type('audio')
            ->pricingUnit('characters')
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: 400));
    }
}
