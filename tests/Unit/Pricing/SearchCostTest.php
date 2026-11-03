<?php

declare(strict_types=1);

use Spectra\Support\Pricing\RequestCostCalculator;
use Spectra\Support\Tracking\RequestContext;

/**
 * Per-search billing only charges searches the request actually ran.
 */
it('bills per-search models using the tracked search count', function () {
    $context = new RequestContext([
        'provider' => 'cohere',
        'model' => 'rerank-v3.5',
        'search_count' => 3,
    ]);

    $cost = app(RequestCostCalculator::class)->forContext($context);

    // 0.2 cents/search × 3 searches
    expect($cost['total_cost_in_cents'])->toEqualWithDelta(0.6, 1e-9);
});

it('does not bill per-search models when no search was processed', function () {
    // A failed request never goes through response processing, so no
    // search count is recorded — it must not be billed as one search.
    $context = new RequestContext([
        'provider' => 'cohere',
        'model' => 'rerank-v3.5',
    ]);

    $cost = app(RequestCostCalculator::class)->forContext($context);

    expect($cost['total_cost_in_cents'])->toEqualWithDelta(0.0, 1e-9);
});
