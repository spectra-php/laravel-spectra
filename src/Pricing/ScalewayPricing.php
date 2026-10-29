<?php

declare(strict_types=1);

namespace Spectra\Pricing;

/**
 * Scaleway Generative APIs (serverless) prices, in EURO cents per 1M tokens
 * (Whisper: per audio minute). Costs are converted to the reporting currency
 * with `spectra.costs.exchange_rates`.
 *
 * Not modelled per request: the free tier (the first 1,000,000 tokens and
 * 60 audio minutes) and the 50% discount of the Batches API.
 *
 * Models only offered as dedicated deployments are not listed: those run on
 * their own hosts and are not tracked. End-of-life models stay for history;
 * Scaleway routes their requests to a replacement model.
 *
 * Source: https://www.scaleway.com/en/pricing/model-as-a-service/ (Paris)
 * and https://www.scaleway.com/en/docs/generative-apis/reference-content/supported-models/
 * (last reviewed 2026-10-29).
 */
class ScalewayPricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'scaleway';
    }

    public function currency(): string
    {
        return 'EUR';
    }

    protected function define(): void
    {
        $this->model('glm-5.3', fn ($m) => $m
            ->displayName('GLM 5.3')
            ->canGenerateText()
            ->tier('standard', inputPrice: 180, outputPrice: 550));

        $this->model('glm-5.2', fn ($m) => $m
            ->displayName('GLM 5.2')
            ->canGenerateText()
            ->tier('standard', inputPrice: 180, outputPrice: 550));

        $this->model('deepseek-v4.1-flash', fn ($m) => $m
            ->displayName('DeepSeek V4.1 Flash')
            ->aliases('deepseek-v4.1-flash-1017')
            ->canGenerateText()
            ->tier('standard', inputPrice: 60, outputPrice: 180, cachedInputPrice: 12));

        $this->model('deepseek-v4-flash-0731', fn ($m) => $m
            ->displayName('DeepSeek V4 Flash 0731')
            ->canGenerateText()
            ->tier('standard', inputPrice: 40, outputPrice: 80, cachedInputPrice: 8));

        $this->model('qwen3.8-27b', fn ($m) => $m
            ->displayName('Qwen 3.8 27B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 60, outputPrice: 330, cachedInputPrice: 12));

        $this->model('qwen3.6-35b-a3b', fn ($m) => $m
            ->displayName('Qwen 3.6 35B A3B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 25, outputPrice: 150));

        $this->model('qwen3.5-397b-a17b', fn ($m) => $m
            ->displayName('Qwen 3.5 397B A17B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 60, outputPrice: 360));

        $this->model('qwen3-235b-a22b-instruct-2507', fn ($m) => $m
            ->displayName('Qwen 3 235B A22B Instruct')
            ->canGenerateText()
            ->tier('standard', inputPrice: 75, outputPrice: 225));

        $this->model('mistral-medium-3.5-128b', fn ($m) => $m
            ->displayName('Mistral Medium 3.5')
            ->canGenerateText()
            ->tier('standard', inputPrice: 150, outputPrice: 750));

        $this->model('mistral-small-3.2-24b-instruct-2506', fn ($m) => $m
            ->displayName('Mistral Small 3.2 24B Instruct')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15, outputPrice: 35));

        $this->model('gemma-4-26b-a4b-it', fn ($m) => $m
            ->displayName('Gemma 4 26B A4B IT')
            ->canGenerateText()
            ->tier('standard', inputPrice: 25, outputPrice: 50));

        $this->model('llama-3.3-70b-instruct', fn ($m) => $m
            ->displayName('Llama 3.3 70B Instruct')
            ->canGenerateText()
            ->tier('standard', inputPrice: 90, outputPrice: 90));

        $this->model('gpt-oss-120b', fn ($m) => $m
            ->displayName('GPT OSS 120B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15, outputPrice: 60));

        // OCR model: input €2.20 per 1M tokens, output free.
        $this->model('lightonocr-3-0.8b', fn ($m) => $m
            ->displayName('LightOnOCR 3 0.8B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 220, outputPrice: 0));

        $this->model('whisper-large-v3', fn ($m) => $m
            ->displayName('Whisper Large v3')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateText()
            ->tier('standard', pricePerUnit: 0.3));

        $this->model('qwen3-embedding-8b', fn ($m) => $m
            ->displayName('Qwen 3 Embedding 8B')
            ->type('embedding')
            ->tier('standard', inputPrice: 10, outputPrice: 0));

        $this->model('bge-multilingual-gemma2', fn ($m) => $m
            ->displayName('BGE Multilingual Gemma 2')
            ->type('embedding')
            ->tier('standard', inputPrice: 10, outputPrice: 0));

        // Marked end-of-life on the supported-models page; no date published.
        $this->model('llama-3.1-8b-instruct', fn ($m) => $m
            ->displayName('Llama 3.1 8B Instruct')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 10));

        // End of life (dates from the supported-models page)
        $this->model('pixtral-12b-2409', fn ($m) => $m
            ->displayName('Pixtral 12B')
            ->retiredOn('2026-10-01')
            ->canGenerateText()
            ->tier('standard', inputPrice: 20, outputPrice: 20));

        $this->model('qwen3-coder-30b-a3b-instruct', fn ($m) => $m
            ->displayName('Qwen 3 Coder 30B A3B Instruct')
            ->retiredOn('2026-10-01')
            ->canGenerateText()
            ->tier('standard', inputPrice: 20, outputPrice: 80));

        $this->model('holo2-30b-a3b', fn ($m) => $m
            ->displayName('Holo 2 30B A3B')
            ->retiredOn('2026-08-09')
            ->canGenerateText()
            ->tier('standard', inputPrice: 30, outputPrice: 70));

        $this->model('gemma-3-27b-it', fn ($m) => $m
            ->displayName('Gemma 3 27B IT')
            ->retiredOn('2026-08-01')
            ->canGenerateText()
            ->tier('standard', inputPrice: 25, outputPrice: 50));

        $this->model('devstral-2-123b-instruct-2512', fn ($m) => $m
            ->displayName('Devstral 2 123B Instruct')
            ->retiredOn('2026-08-01')
            ->canGenerateText()
            ->tier('standard', inputPrice: 40, outputPrice: 200));

        $this->model('voxtral-small-24b-2507', fn ($m) => $m
            ->displayName('Voxtral Small 24B')
            ->retiredOn('2026-08-01')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15, outputPrice: 35));

        $this->model('deepseek-r1-distill-llama-70b', fn ($m) => $m
            ->displayName('DeepSeek R1 Distill Llama 70B')
            ->retiredOn('2026-04-16')
            ->canGenerateText()
            ->tier('standard', inputPrice: 90, outputPrice: 90));

        $this->model('mistral-nemo-instruct-2407', fn ($m) => $m
            ->displayName('Mistral Nemo Instruct')
            ->retiredOn('2026-04-16')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15, outputPrice: 15));

        $this->model('mistral-small-3.1-24b-instruct-2503', fn ($m) => $m
            ->displayName('Mistral Small 3.1 24B Instruct')
            ->retiredOn('2025-11-14')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15, outputPrice: 35));

        $this->model('devstral-small-2505', fn ($m) => $m
            ->displayName('Devstral Small')
            ->retiredOn('2025-11-14')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 30));

        $this->model('qwen2.5-coder-32b-instruct', fn ($m) => $m
            ->displayName('Qwen 2.5 Coder 32B Instruct')
            ->retiredOn('2025-11-14')
            ->canGenerateText()
            ->tier('standard', inputPrice: 90, outputPrice: 90));

        $this->model('llama-3.1-70b-instruct', fn ($m) => $m
            ->displayName('Llama 3.1 70B Instruct')
            ->retiredOn('2025-05-25')
            ->canGenerateText()
            ->tier('standard', inputPrice: 90, outputPrice: 90));

        $this->model('sentence-t5-xxl', fn ($m) => $m
            ->displayName('Sentence T5 XXL')
            ->retiredOn('2025-02-26')
            ->type('embedding')
            ->tier('standard', inputPrice: 10, outputPrice: 0));
    }
}
