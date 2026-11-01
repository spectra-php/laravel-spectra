<?php

declare(strict_types=1);

namespace Spectra\Tests\Fixtures;

use Spectra\Pricing\ProviderPricing;

/**
 * A second catalog for the same provider slug, as an app might register for
 * its fine-tunes next to the built-in OpenAI catalog.
 */
class FakeOpenAIExtraPricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'openai';
    }

    protected function define(): void
    {
        $this->model('ft:gpt-x:acme', fn ($m) => $m
            ->canGenerateText()
            ->cost(inputPrice: 300, outputPrice: 1200));
    }
}
