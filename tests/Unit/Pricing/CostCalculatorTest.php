<?php

declare(strict_types=1);

use Spectra\Enums\PricingTier;
use Spectra\Support\Pricing\CostCalculator;

/**
 * CostCalculator against the built-in catalog: unknown models bill nothing,
 * a PricingTier enum picks its tier, and per-minute and per-character units
 * scale the quantity before pricing it.
 */
it('returns zero cost for unknown models', function () {
    $costs = (new CostCalculator)->calculate('unknown', 'unknown-model', 1000, 500);

    expect($costs['prompt_cost'])->toEqualWithDelta(0.0, 1e-9)
        ->and($costs['completion_cost'])->toEqualWithDelta(0.0, 1e-9)
        ->and($costs['total_cost_in_cents'])->toEqualWithDelta(0.0, 1e-9);
});

it('charges less on the openai batch tier than on standard', function () {
    $calculator = new CostCalculator;

    $standardCosts = $calculator->calculate('openai', 'gpt-4o', 1_000_000, 1_000_000, 0, PricingTier::Standard);
    $batchCosts = $calculator->calculate('openai', 'gpt-4o', 1_000_000, 1_000_000, 0, PricingTier::Batch);

    // Standard: 1M × 250 in, 1M × 1000 out (cents per 1M); batch: 1M × 125 in, 1M × 500 out.
    expect($standardCosts['prompt_cost'])->toEqualWithDelta(250.0, 1e-9)
        ->and($standardCosts['completion_cost'])->toEqualWithDelta(1000.0, 1e-9)
        ->and($batchCosts['prompt_cost'])->toEqualWithDelta(125.0, 1e-9)
        ->and($batchCosts['completion_cost'])->toEqualWithDelta(500.0, 1e-9)
        ->and($batchCosts['total_cost_in_cents'])->toBeLessThan($standardCosts['total_cost_in_cents']);
});

it('bills minute-priced audio by the duration in minutes', function () {
    $cost = (new CostCalculator)->calculateByDuration('openai', 'whisper-1', 120.0);

    // 0.6 cents/minute × (120s / 60)
    expect($cost['total_cost_in_cents'])->toEqualWithDelta(1.2, 1e-9);
});

it('bills character-priced tts per million characters', function () {
    $cost = app(CostCalculator::class)->calculateByCharacters('openai', 'tts-1', 1_000);

    // 1500 cents per 1M characters × (1,000 / 1M)
    expect($cost['total_cost_in_cents'])->toEqualWithDelta(1.5, 1e-9);
});

it('returns zero per-unit cost for unknown models', function () {
    $calculator = new CostCalculator;

    expect($calculator->calculateBySearches('unknown', 'nope')['total_cost_in_cents'])->toEqualWithDelta(0.0, 1e-9)
        ->and($calculator->calculateByImages('unknown', 'nope', 5)['total_cost_in_cents'])->toEqualWithDelta(0.0, 1e-9);
});
