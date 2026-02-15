<?php

namespace Spectra\Pricing;

class GroqPricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'groq';
    }

    protected function define(): void
    {
        $this->model('llama-3.1-8b-instant', fn ($m) => $m
            ->displayName('Llama 3.1 8B Instant')
            ->canGenerateText()
            ->tier('standard', inputPrice: 5, outputPrice: 8));

        $this->model('llama-4-scout-17b-16e-instruct', fn ($m) => $m
            ->displayName('Llama 4 Scout')
            ->canGenerateText()
            ->tier('standard', inputPrice: 11, outputPrice: 34));

        $this->model('llama-4-maverick-17b-128e-instruct', fn ($m) => $m
            ->displayName('Llama 4 Maverick')
            ->canGenerateText()
            ->tier('standard', inputPrice: 20, outputPrice: 60));

        $this->model('llama-3.3-70b-versatile', fn ($m) => $m
            ->displayName('Llama 3.3 70B Versatile')
            ->canGenerateText()
            ->tier('standard', inputPrice: 59, outputPrice: 79));

        $this->model('llama-guard-4-12b', fn ($m) => $m
            ->displayName('Llama Guard 4 12B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 20, outputPrice: 20));

        $this->model('gemma2-9b-it', fn ($m) => $m
            ->displayName('Gemma 2 9B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 20, outputPrice: 20));

        $this->model('qwen-qwq-32b', fn ($m) => $m
            ->displayName('QwQ 32B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 29, outputPrice: 39));

        $this->model('qwen3-32b', fn ($m) => $m
            ->displayName('Qwen3 32B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 29, outputPrice: 59));

        $this->model('kimi-k2-0905', fn ($m) => $m
            ->displayName('Kimi K2')
            ->canGenerateText()
            ->tier('standard', inputPrice: 100, outputPrice: 300));

        $this->model('gpt-oss-20b', fn ($m) => $m
            ->displayName('GPT OSS 20B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 7.5, outputPrice: 30));

        $this->model('gpt-oss-120b', fn ($m) => $m
            ->displayName('GPT OSS 120B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15, outputPrice: 60));

        $this->model('whisper-large-v3', fn ($m) => $m
            ->displayName('Whisper Large v3')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateText()
            ->tier('standard', pricePerUnit: 0.185));

        $this->model('whisper-large-v3-turbo', fn ($m) => $m
            ->displayName('Whisper Large v3 Turbo')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateText()
            ->tier('standard', pricePerUnit: 0.067));

        $this->model('distil-whisper-large-v3-en', fn ($m) => $m
            ->displayName('Distil-Whisper Large v3 EN')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateText()
            ->tier('standard', pricePerUnit: 0.033));
    }
}
