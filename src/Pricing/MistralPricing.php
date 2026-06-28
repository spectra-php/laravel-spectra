<?php

declare(strict_types=1);

namespace Spectra\Pricing;

class MistralPricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'mistral';
    }

    protected function define(): void
    {
        $this->model('mistral-large-latest', fn ($m) => $m
            ->displayName('Mistral Large 3')
            ->canGenerateText()
            ->tier('standard', inputPrice: 50, outputPrice: 150));

        $this->model('mistral-medium-latest', fn ($m) => $m
            ->displayName('Mistral Medium 3.5')
            ->canGenerateText()
            ->tier('standard', inputPrice: 150, outputPrice: 750));

        $this->model('mistral-small-latest', fn ($m) => $m
            ->displayName('Mistral Small 4')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15, outputPrice: 60));

        $this->model('mistral-small-creative', fn ($m) => $m
            ->displayName('Mistral Small Creative')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 30));

        $this->model('mistral-saba-latest', fn ($m) => $m
            ->displayName('Mistral Saba')
            ->canGenerateText()
            ->tier('standard', inputPrice: 20, outputPrice: 60));

        $this->model('ministral-3b-latest', fn ($m) => $m
            ->displayName('Ministral 3B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 10));

        $this->model('ministral-8b-latest', fn ($m) => $m
            ->displayName('Ministral 8B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15, outputPrice: 15));

        $this->model('ministral-14b-latest', fn ($m) => $m
            ->displayName('Ministral 14B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 20, outputPrice: 20));

        $this->model('mistral-nemo', fn ($m) => $m
            ->displayName('Mistral Nemo')
            ->canGenerateText()
            ->tier('standard', inputPrice: 2, outputPrice: 4));

        $this->model('pixtral-large-latest', fn ($m) => $m
            ->displayName('Pixtral Large')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 600));

        $this->model('magistral-medium-latest', fn ($m) => $m
            ->displayName('Magistral Medium')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 500));

        $this->model('magistral-small-latest', fn ($m) => $m
            ->displayName('Magistral Small')
            ->canGenerateText()
            ->tier('standard', inputPrice: 50, outputPrice: 150));

        $this->model('codestral-latest', fn ($m) => $m
            ->displayName('Codestral')
            ->canGenerateText()
            ->tier('standard', inputPrice: 30, outputPrice: 90));

        $this->model('devstral-large-latest', fn ($m) => $m
            ->displayName('Devstral 2')
            ->canGenerateText()
            ->tier('standard', inputPrice: 5, outputPrice: 22));

        $this->model('devstral-small-latest', fn ($m) => $m
            ->displayName('Devstral Small')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 30));

        $this->model('voxtral-small-latest', fn ($m) => $m
            ->displayName('Voxtral Small')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 40));

        $this->model('devstral-medium-latest', fn ($m) => $m
            ->displayName('Devstral 2')
            ->canGenerateText()
            ->tier('standard', inputPrice: 40, outputPrice: 200));

        $this->model('open-mistral-nemo', fn ($m) => $m
            ->displayName('Mistral NeMo')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15, outputPrice: 15));

        $this->model('open-mixtral-8x7b', fn ($m) => $m
            ->displayName('Mixtral 8x7B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 70, outputPrice: 70));

        $this->model('open-mixtral-8x22b', fn ($m) => $m
            ->displayName('Mixtral 8x22B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 600));

        $this->model('mistral-moderation-2603', fn ($m) => $m
            ->displayName('Mistral Moderation')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 0));

        $this->model('mistral-embed', fn ($m) => $m
            ->displayName('Mistral Embed')
            ->type('embedding')
            ->tier('standard', inputPrice: 10, outputPrice: 0));

        $this->model('codestral-embed', fn ($m) => $m
            ->displayName('Codestral Embed')
            ->type('embedding')
            ->tier('standard', inputPrice: 15, outputPrice: 0));

        $this->model('codestral-embed-latest', fn ($m) => $m
            ->displayName('Codestral Embed')
            ->type('embedding')
            ->tier('standard', inputPrice: 15, outputPrice: 0));

        $this->model('voxtral-mini-tts-latest', fn ($m) => $m
            ->displayName('Voxtral Mini TTS')
            ->type('audio')
            ->pricingUnit('characters')
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: 1600));

        $this->model('voxtral-mini-latest', fn ($m) => $m
            ->displayName('Voxtral Mini Transcribe')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateText()
            ->tier('standard', pricePerUnit: 0.3));
    }
}
