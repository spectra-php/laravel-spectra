<?php

declare(strict_types=1);

namespace Spectra\Pricing;

/**
 * Cohere prices, from cohere.com/pricing (including its legacy-model FAQ) and
 * docs.cohere.com/docs/models.
 *
 * Rerank bills per search unit and Parse per page; everything else bills per
 * billed token. Command A, Embed 4, Embed v3 and Rerank 3.5 are no longer on
 * the pricing page, so their last known prices are kept. Command A+ is left
 * out: the pricing page shows it as free while other pages list a token
 * price, so its price cannot be confirmed.
 */
class CoherePricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'cohere';
    }

    public function sources(): array
    {
        return ['https://cohere.com/pricing'];
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

        // Legacy models, deprecated on 2025-09-15 (no shutdown date published).
        // `command-r` and `command-r-plus` are aliases of the 2024 snapshots.
        $this->model('command-r-plus-04-2024', fn ($m) => $m
            ->displayName('Command R+ 04-2024')
            ->aliases('command-r-plus')
            ->canGenerateText()
            ->tier('standard', inputPrice: 300, outputPrice: 1500));

        $this->model('command-r-03-2024', fn ($m) => $m
            ->displayName('Command R 03-2024')
            ->aliases('command-r')
            ->canGenerateText()
            ->tier('standard', inputPrice: 50, outputPrice: 150));

        $this->model('command', fn ($m) => $m
            ->displayName('Command')
            ->canGenerateText()
            ->tier('standard', inputPrice: 100, outputPrice: 200));

        $this->model('command-light', fn ($m) => $m
            ->displayName('Command Light')
            ->canGenerateText()
            ->tier('standard', inputPrice: 30, outputPrice: 60));

        $this->model('c4ai-aya-expanse-8b', fn ($m) => $m
            ->displayName('Aya Expanse 8B')
            ->aliases('aya-expanse-8b')
            ->retiredOn('2026-04-04')
            ->canGenerateText()
            ->tier('standard', inputPrice: 50, outputPrice: 150));

        $this->model('c4ai-aya-expanse-32b', fn ($m) => $m
            ->displayName('Aya Expanse 32B')
            ->aliases('aya-expanse-32b')
            ->canGenerateText()
            ->tier('standard', inputPrice: 50, outputPrice: 150));

        $this->model('embed-v5.0-pro', fn ($m) => $m
            ->displayName('Embed 5 Pro')
            ->type('embedding')
            ->tier('standard', inputPrice: 12, outputPrice: 0));

        $this->model('embed-v5.0-fast', fn ($m) => $m
            ->displayName('Embed 5 Fast')
            ->type('embedding')
            ->tier('standard', inputPrice: 8, outputPrice: 0));

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

        // Rerank: $2.50 / 1K searches (Pro) and $2 / 1K searches (Fast).
        $this->model('rerank-v4.0-pro', fn ($m) => $m
            ->displayName('Rerank 4 Pro')
            ->type('text')
            ->pricingUnit('search')
            ->tier('standard', pricePerUnit: 0.25));

        $this->model('rerank-v4.0-fast', fn ($m) => $m
            ->displayName('Rerank 4 Fast')
            ->type('text')
            ->pricingUnit('search')
            ->tier('standard', pricePerUnit: 0.2));

        $this->model('rerank-v3.5', fn ($m) => $m
            ->displayName('Rerank 3.5')
            ->type('text')
            ->pricingUnit('search')
            ->tier('standard', pricePerUnit: 0.2));

        // Parse: $1.50 / 1K pages.
        $this->model('parse-v5.0', fn ($m) => $m
            ->displayName('Parse 5')
            ->type('text')
            ->pricingUnit('page')
            ->tier('standard', pricePerUnit: 0.15));
    }
}
