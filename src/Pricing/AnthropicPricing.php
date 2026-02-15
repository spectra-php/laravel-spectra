<?php

namespace Spectra\Pricing;

class AnthropicPricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'anthropic';
    }

    protected function define(): void
    {
        $this->model('claude-opus-4-6', fn ($m) => $m
            ->displayName('Claude Opus 4.6')
            ->canGenerateText()
            ->tier('standard', inputPrice: 500, outputPrice: 2500, cachedInputPrice: 50, cacheWrite5mPrice: 625, cacheWrite1hPrice: 1000)
            ->tier('batch', inputPrice: 250, outputPrice: 1250, cachedInputPrice: 25, cacheWrite5mPrice: 312.5, cacheWrite1hPrice: 500));

        $this->model('claude-opus-4-5-20251101', fn ($m) => $m
            ->displayName('Claude Opus 4.5')
            ->canGenerateText()
            ->tier('standard', inputPrice: 500, outputPrice: 2500, cachedInputPrice: 50, cacheWrite5mPrice: 625, cacheWrite1hPrice: 1000)
            ->tier('batch', inputPrice: 250, outputPrice: 1250, cachedInputPrice: 25, cacheWrite5mPrice: 312.5, cacheWrite1hPrice: 500));

        $this->model('claude-opus-4-1-20250805', fn ($m) => $m
            ->displayName('Claude Opus 4.1')
            ->canGenerateText()
            ->tier('standard', inputPrice: 1500, outputPrice: 7500, cachedInputPrice: 150, cacheWrite5mPrice: 1875, cacheWrite1hPrice: 3000)
            ->tier('batch', inputPrice: 750, outputPrice: 3750, cachedInputPrice: 75, cacheWrite5mPrice: 937.5, cacheWrite1hPrice: 1500));

        $this->model('claude-opus-4-20250514', fn ($m) => $m
            ->displayName('Claude Opus 4')
            ->canGenerateText()
            ->tier('standard', inputPrice: 1500, outputPrice: 7500, cachedInputPrice: 150, cacheWrite5mPrice: 1875, cacheWrite1hPrice: 3000)
            ->tier('batch', inputPrice: 750, outputPrice: 3750, cachedInputPrice: 75, cacheWrite5mPrice: 937.5, cacheWrite1hPrice: 1500));

        $this->model('claude-sonnet-4-5-20250929', fn ($m) => $m
            ->displayName('Claude Sonnet 4.5')
            ->canGenerateText()
            ->tier('standard', inputPrice: 300, outputPrice: 1500, cachedInputPrice: 30, cacheWrite5mPrice: 375, cacheWrite1hPrice: 600)
            ->tier('batch', inputPrice: 150, outputPrice: 750, cachedInputPrice: 15, cacheWrite5mPrice: 187.5, cacheWrite1hPrice: 300));

        $this->model('claude-sonnet-4-20250514', fn ($m) => $m
            ->displayName('Claude Sonnet 4')
            ->canGenerateText()
            ->tier('standard', inputPrice: 300, outputPrice: 1500, cachedInputPrice: 30, cacheWrite5mPrice: 375, cacheWrite1hPrice: 600)
            ->tier('batch', inputPrice: 150, outputPrice: 750, cachedInputPrice: 15, cacheWrite5mPrice: 187.5, cacheWrite1hPrice: 300));

        $this->model('claude-3-7-sonnet-20250219', fn ($m) => $m
            ->displayName('Claude 3.7 Sonnet')
            ->canGenerateText()
            ->tier('standard', inputPrice: 300, outputPrice: 1500, cachedInputPrice: 30, cacheWrite5mPrice: 375, cacheWrite1hPrice: 600)
            ->tier('batch', inputPrice: 150, outputPrice: 750, cachedInputPrice: 15, cacheWrite5mPrice: 187.5, cacheWrite1hPrice: 300));

        $this->model('claude-haiku-4-5-20251001', fn ($m) => $m
            ->displayName('Claude Haiku 4.5')
            ->canGenerateText()
            ->tier('standard', inputPrice: 100, outputPrice: 500, cachedInputPrice: 10, cacheWrite5mPrice: 125, cacheWrite1hPrice: 200)
            ->tier('batch', inputPrice: 50, outputPrice: 250, cachedInputPrice: 5, cacheWrite5mPrice: 62.5, cacheWrite1hPrice: 100));

        $this->model('claude-3-5-haiku-20241022', fn ($m) => $m
            ->displayName('Claude 3.5 Haiku')
            ->canGenerateText()
            ->tier('standard', inputPrice: 80, outputPrice: 400, cachedInputPrice: 8, cacheWrite5mPrice: 100, cacheWrite1hPrice: 160)
            ->tier('batch', inputPrice: 40, outputPrice: 200, cachedInputPrice: 4, cacheWrite5mPrice: 50, cacheWrite1hPrice: 80));

        $this->model('claude-3-haiku-20240307', fn ($m) => $m
            ->displayName('Claude 3 Haiku')
            ->canGenerateText()
            ->tier('standard', inputPrice: 25, outputPrice: 125, cachedInputPrice: 3, cacheWrite5mPrice: 30, cacheWrite1hPrice: 50)
            ->tier('batch', inputPrice: 12.5, outputPrice: 62.5, cachedInputPrice: 1.5, cacheWrite5mPrice: 15, cacheWrite1hPrice: 25));
    }
}
