<?php

declare(strict_types=1);

namespace Spectra\Pricing;

class ScalewayPricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'scaleway';
    }

    protected function define(): void
    {
        $this->model('mistral-large-3-675b-instruct-2512', fn ($m) => $m
            ->displayName('Mistral Large 3 675B Instruct')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 800));

        $this->model('qwen3.5-397b-a17b', fn ($m) => $m
            ->displayName('Qwen 3.5 397B A17B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 60, outputPrice: 360));

        $this->model('qwen3.5-122b-a10b', fn ($m) => $m
            ->displayName('Qwen 3.5 122B A10B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 40, outputPrice: 240));

        $this->model('qwen3-235b-a22b-instruct-2507', fn ($m) => $m
            ->displayName('Qwen 3 235B A22B Instruct')
            ->canGenerateText()
            ->tier('standard', inputPrice: 75, outputPrice: 225));

        $this->model('qwen3-235b-a22b-thinking-2507', fn ($m) => $m
            ->displayName('Qwen 3 235B A22B Thinking')
            ->canGenerateText()
            ->tier('standard', inputPrice: 75, outputPrice: 225));

        $this->model('mistral-medium-3.5-128b', fn ($m) => $m
            ->displayName('Mistral Medium 3.5')
            ->canGenerateText()
            ->tier('standard', inputPrice: 150, outputPrice: 750));

        $this->model('llama-3.3-70b-instruct', fn ($m) => $m
            ->displayName('Llama 3.3 70B Instruct')
            ->canGenerateText()
            ->tier('standard', inputPrice: 90, outputPrice: 90));

        $this->model('llama-3.1-70b-instruct', fn ($m) => $m
            ->displayName('Llama 3.1 70B Instruct')
            ->canGenerateText()
            ->tier('standard', inputPrice: 90, outputPrice: 90));

        $this->model('llama-3-70b-instruct', fn ($m) => $m
            ->displayName('Llama 3 70B Instruct')
            ->canGenerateText()
            ->tier('standard', inputPrice: 90, outputPrice: 90));

        $this->model('llama-3.1-nemotron-70b-instruct', fn ($m) => $m
            ->displayName('Llama 3.1 Nemotron 70B Instruct')
            ->canGenerateText()
            ->tier('standard', inputPrice: 90, outputPrice: 90));

        $this->model('deepseek-r1-distill-llama-70b', fn ($m) => $m
            ->displayName('DeepSeek R1 Distill Llama 70B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 90, outputPrice: 90));

        $this->model('molmo-72b-0924', fn ($m) => $m
            ->displayName('Molmo 72B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 90, outputPrice: 90));

        $this->model('qwen3.6-35b-a3b', fn ($m) => $m
            ->displayName('Qwen 3.6 35B A3B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 25, outputPrice: 150));

        $this->model('qwen3.5-35b-a3b', fn ($m) => $m
            ->displayName('Qwen 3.5 35B A3B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 25, outputPrice: 150));

        $this->model('gemma-4-31b-it', fn ($m) => $m
            ->displayName('Gemma 4 31B IT')
            ->canGenerateText()
            ->tier('standard', inputPrice: 25, outputPrice: 50));

        $this->model('gemma-4-26b-a4b-it', fn ($m) => $m
            ->displayName('Gemma 4 26B A4B IT')
            ->canGenerateText()
            ->tier('standard', inputPrice: 25, outputPrice: 50));

        $this->model('gemma-3-27b-it', fn ($m) => $m
            ->displayName('Gemma 3 27B IT')
            ->canGenerateText()
            ->tier('standard', inputPrice: 25, outputPrice: 50));

        $this->model('gpt-oss-120b', fn ($m) => $m
            ->displayName('GPT OSS 120B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15, outputPrice: 60));

        $this->model('gpt-oss-20b', fn ($m) => $m
            ->displayName('GPT OSS 20B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 5, outputPrice: 20));

        $this->model('mistral-small-3.2-24b-instruct-2506', fn ($m) => $m
            ->displayName('Mistral Small 3.2 24B Instruct')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15, outputPrice: 35));

        $this->model('mistral-small-3.1-24b-instruct-2503', fn ($m) => $m
            ->displayName('Mistral Small 3.1 24B Instruct')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15, outputPrice: 35));

        $this->model('mistral-small-24b-instruct-2501', fn ($m) => $m
            ->displayName('Mistral Small 24B Instruct')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15, outputPrice: 35));

        $this->model('mistral-nemo-instruct-2407', fn ($m) => $m
            ->displayName('Mistral Nemo Instruct')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15, outputPrice: 15));

        $this->model('mistral-7b-instruct-v0.3', fn ($m) => $m
            ->displayName('Mistral 7B Instruct v0.3')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 10));

        $this->model('mixtral-8x7b-instruct-v0.1', fn ($m) => $m
            ->displayName('Mixtral 8x7B Instruct v0.1')
            ->canGenerateText()
            ->tier('standard', inputPrice: 20, outputPrice: 60));

        $this->model('magistral-small-2506', fn ($m) => $m
            ->displayName('Magistral Small')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15, outputPrice: 35));

        $this->model('pixtral-12b-2409', fn ($m) => $m
            ->displayName('Pixtral 12B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 20, outputPrice: 20));

        $this->model('holo2-30b-a3b', fn ($m) => $m
            ->displayName('Holo 2 30B A3B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 30, outputPrice: 70));

        $this->model('minimax-m2.5', fn ($m) => $m
            ->displayName('MiniMax M2.5')
            ->canGenerateText()
            ->tier('standard', inputPrice: 0, outputPrice: 0));

        $this->model('llama-3.1-8b-instruct', fn ($m) => $m
            ->displayName('Llama 3.1 8B Instruct')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 10));

        $this->model('llama-3-8b-instruct', fn ($m) => $m
            ->displayName('Llama 3 8B Instruct')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 10));

        $this->model('deepseek-r1-distill-llama-8b', fn ($m) => $m
            ->displayName('DeepSeek R1 Distill Llama 8B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 10));

        $this->model('devstral-2-123b-instruct-2512', fn ($m) => $m
            ->displayName('Devstral 2 123B Instruct')
            ->canGenerateText()
            ->tier('standard', inputPrice: 40, outputPrice: 200));

        $this->model('devstral-small-2505', fn ($m) => $m
            ->displayName('Devstral Small')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 30));

        $this->model('qwen3-coder-30b-a3b-instruct', fn ($m) => $m
            ->displayName('Qwen 3 Coder 30B A3B Instruct')
            ->canGenerateText()
            ->tier('standard', inputPrice: 20, outputPrice: 80));

        $this->model('qwen2.5-coder-32b-instruct', fn ($m) => $m
            ->displayName('Qwen 2.5 Coder 32B Instruct')
            ->canGenerateText()
            ->tier('standard', inputPrice: 90, outputPrice: 90));

        $this->model('voxtral-small-24b-2507', fn ($m) => $m
            ->displayName('Voxtral Small 24B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15, outputPrice: 35));

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

        $this->model('sentence-t5-xxl', fn ($m) => $m
            ->displayName('Sentence T5 XXL')
            ->type('embedding')
            ->tier('standard', inputPrice: 10, outputPrice: 0));
    }
}
