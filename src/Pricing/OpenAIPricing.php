<?php

namespace Spectra\Pricing;

class OpenAIPricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'openai';
    }

    protected function define(): void
    {
        $this->model('gpt-5.2', fn ($m) => $m
            ->displayName('GPT-5.2')
            ->canGenerateText()
            ->tier('standard', inputPrice: 175, outputPrice: 1400, cachedInputPrice: 17.5)
            ->tier('batch', inputPrice: 87.5, outputPrice: 700, cachedInputPrice: 8.75)
            ->tier('flex', inputPrice: 87.5, outputPrice: 700, cachedInputPrice: 8.75)
            ->tier('priority', inputPrice: 350, outputPrice: 2800, cachedInputPrice: 35));

        $this->model('gpt-5.1', fn ($m) => $m
            ->displayName('GPT-5.1')
            ->canGenerateText()
            ->tier('standard', inputPrice: 125, outputPrice: 1000, cachedInputPrice: 12.5)
            ->tier('batch', inputPrice: 62.5, outputPrice: 500, cachedInputPrice: 6.25)
            ->tier('flex', inputPrice: 62.5, outputPrice: 500, cachedInputPrice: 6.25)
            ->tier('priority', inputPrice: 250, outputPrice: 2000, cachedInputPrice: 25));

        $this->model('gpt-5', fn ($m) => $m
            ->displayName('GPT-5')
            ->canGenerateText()
            ->tier('standard', inputPrice: 125, outputPrice: 1000, cachedInputPrice: 12.5)
            ->tier('batch', inputPrice: 62.5, outputPrice: 500, cachedInputPrice: 6.25)
            ->tier('flex', inputPrice: 62.5, outputPrice: 500, cachedInputPrice: 6.25)
            ->tier('priority', inputPrice: 250, outputPrice: 2000, cachedInputPrice: 25));

        $this->model('gpt-5-mini', fn ($m) => $m
            ->displayName('GPT-5 Mini')
            ->canGenerateText()
            ->tier('standard', inputPrice: 25, outputPrice: 200, cachedInputPrice: 2.5)
            ->tier('batch', inputPrice: 12.5, outputPrice: 100, cachedInputPrice: 1.25)
            ->tier('flex', inputPrice: 12.5, outputPrice: 100, cachedInputPrice: 1.25)
            ->tier('priority', inputPrice: 45, outputPrice: 360, cachedInputPrice: 4.5));

        $this->model('gpt-5-nano', fn ($m) => $m
            ->displayName('GPT-5 Nano')
            ->canGenerateText()
            ->tier('standard', inputPrice: 5, outputPrice: 40, cachedInputPrice: 0.5)
            ->tier('batch', inputPrice: 2.5, outputPrice: 20, cachedInputPrice: 0.25)
            ->tier('flex', inputPrice: 2.5, outputPrice: 20, cachedInputPrice: 0.25));

        $this->model('gpt-5.2-pro', fn ($m) => $m
            ->displayName('GPT-5.2 Pro')
            ->canGenerateText()
            ->tier('standard', inputPrice: 2100, outputPrice: 16800)
            ->tier('batch', inputPrice: 1050, outputPrice: 8400));

        $this->model('gpt-5-pro', fn ($m) => $m
            ->displayName('GPT-5 Pro')
            ->canGenerateText()
            ->tier('standard', inputPrice: 1500, outputPrice: 12000)
            ->tier('batch', inputPrice: 750, outputPrice: 6000));

        $this->model('gpt-5.2-codex', fn ($m) => $m
            ->displayName('GPT-5.2 Codex')
            ->canGenerateText()
            ->tier('standard', inputPrice: 175, outputPrice: 1400, cachedInputPrice: 17.5)
            ->tier('priority', inputPrice: 350, outputPrice: 2800, cachedInputPrice: 35));

        $this->model('gpt-5.1-codex-max', fn ($m) => $m
            ->displayName('GPT-5.1 Codex Max')
            ->canGenerateText()
            ->tier('standard', inputPrice: 125, outputPrice: 1000, cachedInputPrice: 12.5)
            ->tier('priority', inputPrice: 250, outputPrice: 2000, cachedInputPrice: 25));

        $this->model('gpt-5.1-codex', fn ($m) => $m
            ->displayName('GPT-5.1 Codex')
            ->canGenerateText()
            ->tier('standard', inputPrice: 125, outputPrice: 1000, cachedInputPrice: 12.5)
            ->tier('priority', inputPrice: 250, outputPrice: 2000, cachedInputPrice: 25));

        $this->model('gpt-5-codex', fn ($m) => $m
            ->displayName('GPT-5 Codex')
            ->canGenerateText()
            ->tier('standard', inputPrice: 125, outputPrice: 1000, cachedInputPrice: 12.5)
            ->tier('priority', inputPrice: 250, outputPrice: 2000, cachedInputPrice: 25));

        $this->model('gpt-5.1-codex-mini', fn ($m) => $m
            ->displayName('GPT-5.1 Codex Mini')
            ->canGenerateText()
            ->tier('standard', inputPrice: 25, outputPrice: 200, cachedInputPrice: 2.5));

        $this->model('gpt-5-search-api', fn ($m) => $m
            ->displayName('GPT-5 Search API')
            ->canGenerateText()
            ->tier('standard', inputPrice: 125, outputPrice: 1000, cachedInputPrice: 12.5));

        $this->model('gpt-4.1', fn ($m) => $m
            ->displayName('GPT-4.1')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 800, cachedInputPrice: 50)
            ->tier('batch', inputPrice: 100, outputPrice: 400)
            ->tier('priority', inputPrice: 350, outputPrice: 1400, cachedInputPrice: 87.5));

        $this->model('gpt-4.1-mini', fn ($m) => $m
            ->displayName('GPT-4.1 Mini')
            ->canGenerateText()
            ->tier('standard', inputPrice: 40, outputPrice: 160, cachedInputPrice: 10)
            ->tier('batch', inputPrice: 20, outputPrice: 80)
            ->tier('priority', inputPrice: 70, outputPrice: 280, cachedInputPrice: 17.5));

        $this->model('gpt-4.1-nano', fn ($m) => $m
            ->displayName('GPT-4.1 Nano')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 40, cachedInputPrice: 2.5)
            ->tier('batch', inputPrice: 5, outputPrice: 20)
            ->tier('priority', inputPrice: 20, outputPrice: 80, cachedInputPrice: 5));

        $this->model('gpt-4o', fn ($m) => $m
            ->displayName('GPT-4o')
            ->canGenerateText()
            ->tier('standard', inputPrice: 250, outputPrice: 1000, cachedInputPrice: 125)
            ->tier('batch', inputPrice: 125, outputPrice: 500)
            ->tier('priority', inputPrice: 425, outputPrice: 1700, cachedInputPrice: 212.5));

        $this->model('gpt-4o-mini', fn ($m) => $m
            ->displayName('GPT-4o Mini')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15, outputPrice: 60, cachedInputPrice: 7.5)
            ->tier('batch', inputPrice: 7.5, outputPrice: 30)
            ->tier('priority', inputPrice: 25, outputPrice: 100, cachedInputPrice: 12.5));

        $this->model('gpt-audio', fn ($m) => $m
            ->displayName('GPT Audio')
            ->canGenerateText()
            ->canGenerateAudio()
            ->tier('standard', inputPrice: 250, outputPrice: 1000));

        $this->model('gpt-audio-mini', fn ($m) => $m
            ->displayName('GPT Audio Mini')
            ->canGenerateText()
            ->canGenerateAudio()
            ->tier('standard', inputPrice: 60, outputPrice: 240));

        $this->model('o1', fn ($m) => $m
            ->displayName('o1')
            ->canGenerateText()
            ->tier('standard', inputPrice: 1500, outputPrice: 6000, cachedInputPrice: 750)
            ->tier('batch', inputPrice: 750, outputPrice: 3000));

        $this->model('o1-pro', fn ($m) => $m
            ->displayName('o1 Pro')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15000, outputPrice: 60000)
            ->tier('batch', inputPrice: 7500, outputPrice: 30000));

        $this->model('o3-pro', fn ($m) => $m
            ->displayName('o3 Pro')
            ->canGenerateText()
            ->tier('standard', inputPrice: 2000, outputPrice: 8000)
            ->tier('batch', inputPrice: 1000, outputPrice: 4000));

        $this->model('o3', fn ($m) => $m
            ->displayName('o3')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 800, cachedInputPrice: 50)
            ->tier('batch', inputPrice: 100, outputPrice: 400)
            ->tier('flex', inputPrice: 100, outputPrice: 400, cachedInputPrice: 25)
            ->tier('priority', inputPrice: 350, outputPrice: 1400, cachedInputPrice: 87.5));

        $this->model('o3-deep-research', fn ($m) => $m
            ->displayName('o3 Deep Research')
            ->canGenerateText()
            ->tier('standard', inputPrice: 1000, outputPrice: 4000, cachedInputPrice: 250)
            ->tier('batch', inputPrice: 500, outputPrice: 2000));

        $this->model('o4-mini', fn ($m) => $m
            ->displayName('o4 Mini')
            ->canGenerateText()
            ->tier('standard', inputPrice: 110, outputPrice: 440, cachedInputPrice: 27.5)
            ->tier('batch', inputPrice: 55, outputPrice: 220)
            ->tier('flex', inputPrice: 55, outputPrice: 220, cachedInputPrice: 13.8)
            ->tier('priority', inputPrice: 200, outputPrice: 800, cachedInputPrice: 50));

        $this->model('o4-mini-deep-research', fn ($m) => $m
            ->displayName('o4 Mini Deep Research')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 800, cachedInputPrice: 50)
            ->tier('batch', inputPrice: 100, outputPrice: 400));

        $this->model('o3-mini', fn ($m) => $m
            ->displayName('o3 Mini')
            ->canGenerateText()
            ->tier('standard', inputPrice: 110, outputPrice: 440, cachedInputPrice: 55)
            ->tier('batch', inputPrice: 55, outputPrice: 220));

        $this->model('computer-use-preview', fn ($m) => $m
            ->displayName('Computer Use Preview')
            ->canGenerateText()
            ->tier('standard', inputPrice: 300, outputPrice: 1200)
            ->tier('batch', inputPrice: 150, outputPrice: 600));

        // Embeddings
        $this->model('text-embedding-3-small', fn ($m) => $m
            ->displayName('Text Embedding 3 Small')
            ->type('embedding')
            ->tier('standard', inputPrice: 2, outputPrice: 0)
            ->tier('batch', inputPrice: 1, outputPrice: 0));

        $this->model('text-embedding-3-large', fn ($m) => $m
            ->displayName('Text Embedding 3 Large')
            ->type('embedding')
            ->tier('standard', inputPrice: 13, outputPrice: 0)
            ->tier('batch', inputPrice: 6.5, outputPrice: 0));

        $this->model('text-embedding-ada-002', fn ($m) => $m
            ->displayName('Text Embedding Ada 002')
            ->type('embedding')
            ->tier('standard', inputPrice: 10, outputPrice: 0)
            ->tier('batch', inputPrice: 5, outputPrice: 0));

        // Audio
        $this->model('whisper-1', fn ($m) => $m
            ->displayName('Whisper')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateText()
            ->tier('standard', pricePerUnit: 0.6));

        $this->model('tts-1', fn ($m) => $m
            ->displayName('TTS')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: 1.5));

        $this->model('tts-1-hd', fn ($m) => $m
            ->displayName('TTS HD')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: 3.0));

        $this->model('gpt-4o-mini-tts', fn ($m) => $m
            ->displayName('GPT-4o Mini TTS')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: 1.5));

        $this->model('gpt-4o-transcribe', fn ($m) => $m
            ->displayName('GPT-4o Transcribe')
            ->type('audio')
            ->canGenerateText()
            ->tier('standard', inputPrice: 250, outputPrice: 1000));

        $this->model('gpt-4o-transcribe-diarize', fn ($m) => $m
            ->displayName('GPT-4o Transcribe Diarize')
            ->type('audio')
            ->canGenerateText()
            ->tier('standard', inputPrice: 250, outputPrice: 1000));

        $this->model('gpt-4o-mini-transcribe', fn ($m) => $m
            ->displayName('GPT-4o Mini Transcribe')
            ->type('audio')
            ->canGenerateText()
            ->tier('standard', inputPrice: 125, outputPrice: 500));

        // Images
        $this->model('gpt-image-1.5', fn ($m) => $m
            ->displayName('GPT Image 1.5')
            ->type('image')
            ->canGenerateImages()
            ->tier('standard', inputPrice: 500, outputPrice: 1000, cachedInputPrice: 125));

        $this->model('gpt-image-1', fn ($m) => $m
            ->displayName('GPT Image 1')
            ->type('image')
            ->canGenerateImages()
            ->tier('standard', inputPrice: 500, outputPrice: 1000, cachedInputPrice: 125));

        $this->model('gpt-image-1-mini', fn ($m) => $m
            ->displayName('GPT Image 1 Mini')
            ->type('image')
            ->canGenerateImages()
            ->tier('standard', inputPrice: 200, outputPrice: 800, cachedInputPrice: 25));

        $this->model('dall-e-3', fn ($m) => $m
            ->displayName('DALL-E 3')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 4)
            ->tier('hd', pricePerUnit: 8));

        $this->model('dall-e-2', fn ($m) => $m
            ->displayName('DALL-E 2')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 1.6));

        // Video
        $this->model('sora-2', fn ($m) => $m
            ->displayName('Sora 2')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 10));

        $this->model('sora-2-pro', fn ($m) => $m
            ->displayName('Sora 2 Pro')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 30)
            ->tier('hd', pricePerUnit: 50));
    }

    public function toolCallPricing(): array
    {
        return [
            'web_search_call' => 1.0,            // $10.00 / 1k calls = $0.01 per call = 1¢
            'file_search_call' => 0.25,           // $2.50 / 1k calls = $0.0025 per call = 0.25¢
            'code_interpreter_call' => 3.0,       // $0.03 per container = 3¢
        ];
    }
}
