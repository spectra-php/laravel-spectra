<?php

declare(strict_types=1);

namespace Spectra\Tests\Fixtures;

use Spectra\Pricing\ProviderPricing;

/**
 * A small, stable catalog for the scraped prices and scraping tests.
 */
class FakeOpenAIPricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'openai';
    }

    public function sources(): array
    {
        return ['https://example.test/pricing'];
    }

    protected function define(): void
    {
        $this->model('gpt-x', fn ($m) => $m
            ->displayName('GPT X')
            ->aliases('gpt-x-latest')
            ->canGenerateText()
            ->tier('standard', inputPrice: 100, outputPrice: 400, cachedInputPrice: 10)
            ->tier('standard', inputPrice: 200, outputPrice: 600, aboveInputTokens: 200_000)
            ->tier('batch', inputPrice: 50, outputPrice: 200));

        $this->model('gpt-y', fn ($m) => $m
            ->displayName('GPT Y')
            ->canGenerateText()
            ->cost(inputPrice: 30, outputPrice: 120));

        $this->model('image-x', fn ($m) => $m
            ->displayName('Image X')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 4)
            ->tier('hd', pricePerUnit: 8));
    }
}
