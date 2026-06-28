<?php

declare(strict_types=1);

use Spectra\Support\Pricing\CostCalculator;
use Spectra\Support\Pricing\PricingLookup;

it('matches a dated model id against an undated catalog entry', function () {
    $lookup = app(PricingLookup::class);

    // Catalog defines `claude-opus-4-8`; the API returns a dated snapshot id.
    $exact = $lookup->get('anthropic', 'claude-opus-4-8');
    $dated = $lookup->get('anthropic', 'claude-opus-4-8-20260528');

    expect($dated)->not->toBeNull()
        ->and($dated)->toBe($exact)
        ->and($dated['input'])->toBe(500)
        ->and($dated['output'])->toBe(2500);
});

it('also tolerates openai-style dashed date suffixes', function () {
    $lookup = app(PricingLookup::class);

    $base = $lookup->get('openai', 'gpt-4o');
    $dated = $lookup->get('openai', 'gpt-4o-2024-08-06');

    expect($dated)->not->toBeNull()->and($dated)->toBe($base);
});

it('resolves pricing unit and display name for dated ids', function () {
    $lookup = app(PricingLookup::class);

    expect($lookup->getDisplayName('anthropic', 'claude-opus-4-8-20260528'))->toBe('Claude Opus 4.8')
        ->and($lookup->getUnit('anthropic', 'claude-opus-4-8-20260528'))->toBe('tokens');
});

it('returns null for genuinely unknown models', function () {
    $lookup = app(PricingLookup::class);

    expect($lookup->get('anthropic', 'claude-nonexistent-9-9-20260101'))->toBeNull();
});

it('prices the newly added anthropic models', function () {
    $calculator = new CostCalculator;

    // Opus 4.8: $5/MTok input, $25/MTok output → 1M in + 1M out = 500 + 2500 cents.
    $opus = $calculator->calculate('anthropic', 'claude-opus-4-8', 1_000_000, 1_000_000);
    expect($opus['total_cost_in_cents'])->toEqual(3000);

    // Sonnet 4.6: $3 / $15.
    $sonnet = $calculator->calculate('anthropic', 'claude-sonnet-4-6', 1_000_000, 1_000_000);
    expect($sonnet['total_cost_in_cents'])->toEqual(1800);

    // Fable 5: $10 / $50.
    $fable = $calculator->calculate('anthropic', 'claude-fable-5', 1_000_000, 1_000_000);
    expect($fable['total_cost_in_cents'])->toEqual(6000);
});
