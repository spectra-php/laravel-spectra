<?php

declare(strict_types=1);

namespace Spectra\Tests\Fixtures;

use Spectra\Pricing\ProviderPricing;

/**
 * A second provider with pricing pages, for choosing which providers to scrape.
 */
class FakeAnthropicPricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'anthropic';
    }

    public function sources(): array
    {
        return ['https://example.test/anthropic-pricing'];
    }

    protected function define(): void
    {
        $this->model('claude-x', fn ($m) => $m
            ->displayName('Claude X')
            ->canGenerateText()
            ->cost(inputPrice: 300, outputPrice: 1500));
    }
}
