<?php

namespace Spectra\Pricing;

class CoherePricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'cohere';
    }

    protected function define(): void
    {
        $this->model('command-a-03-2025', fn ($m) => $m
            ->displayName('Command A')
            ->canGenerateText()
            ->tier('standard', inputPrice: 250, outputPrice: 1000));

        $this->model('command-r-plus-08-2024', fn ($m) => $m
            ->displayName('Command R+')
            ->canGenerateText()
            ->tier('standard', inputPrice: 250, outputPrice: 1000));

        $this->model('command-r-08-2024', fn ($m) => $m
            ->displayName('Command R')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15, outputPrice: 60));

        $this->model('command-r7b-12-2024', fn ($m) => $m
            ->displayName('Command R7B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 3.75, outputPrice: 15));

        $this->model('aya-expanse-8b', fn ($m) => $m
            ->displayName('Aya Expanse 8B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 50, outputPrice: 150));

        $this->model('aya-expanse-32b', fn ($m) => $m
            ->displayName('Aya Expanse 32B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 50, outputPrice: 150));

        $this->model('embed-v4.0', fn ($m) => $m
            ->displayName('Embed 4')
            ->type('embedding')
            ->tier('standard', inputPrice: 12, outputPrice: 0));

        $this->model('embed-english-v3.0', fn ($m) => $m
            ->displayName('Embed v3 English')
            ->type('embedding')
            ->tier('standard', inputPrice: 10, outputPrice: 0));

        $this->model('embed-multilingual-v3.0', fn ($m) => $m
            ->displayName('Embed v3 Multilingual')
            ->type('embedding')
            ->tier('standard', inputPrice: 10, outputPrice: 0));

        $this->model('rerank-v3.5', fn ($m) => $m
            ->displayName('Rerank 3.5')
            ->type('text')
            ->pricingUnit('search')
            ->tier('standard', pricePerUnit: 0.2));
    }
}
