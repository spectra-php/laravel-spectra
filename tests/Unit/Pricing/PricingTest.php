<?php

declare(strict_types=1);

use Spectra\Support\Pricing\PricingLookup;

/**
 * PricingLookup lists catalog models by capability, which the requests
 * filter uses to narrow by modality.
 */
it('lists chat models as text-generating and leaves out image models', function () {
    $textModels = app(PricingLookup::class)->canGenerateText();

    expect($textModels)->toContain('gpt-4o')
        ->and($textModels)->toContain('claude-sonnet-4')
        ->and($textModels)->not->toContain('dall-e-3');
});
