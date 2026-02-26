<?php

use Spectra\Enums\PricingTier;
use Spectra\Support\Pricing\CostCalculator;

it('should calculate costs for a request', function () {
    $calculator = new CostCalculator;

    $costs = $calculator->calculate(
        'openai',
        'gpt-4o',
        1000, // prompt tokens
        500   // completion tokens
    );

    expect($costs)->toHaveKeys(['prompt_cost', 'completion_cost', 'total_cost_in_cents'])
        ->and($costs['prompt_cost'])->toBeNumeric()
        ->and($costs['completion_cost'])->toBeNumeric()
        ->and($costs['total_cost_in_cents'])->toEqual($costs['prompt_cost'] + $costs['completion_cost']);
});

it('returns zero cost for unknown models', function () {
    $calculator = new CostCalculator;

    $costs = $calculator->calculate(
        'unknown',
        'unknown-model',
        1000,
        500
    );

    expect($costs['prompt_cost'])->toEqual(0.0)
        ->and($costs['completion_cost'])->toEqual(0.0)
        ->and($costs['total_cost_in_cents'])->toEqual(0.0);
});

it('handles cached tokens in cost calculation', function () {
    $calculator = new CostCalculator;

    // With cached tokens, cost should be lower
    $costsWithCache = $calculator->calculate(
        'openai',
        'gpt-4o',
        1000,
        500,
        500 // 500 cached tokens
    );

    $costsWithoutCache = $calculator->calculate(
        'openai',
        'gpt-4o',
        1000,
        500,
        0
    );

    // Prompt cost with cache should be less than without
    expect($costsWithCache['prompt_cost'])->toBeLessThanOrEqual($costsWithoutCache['prompt_cost']);
});

it('calculates non-zero cost for small token counts', function () {
    $calculator = new CostCalculator;

    // gpt-4o-mini has pricing: input=15, output=60 cents per 1M tokens
    // Even with just 100 tokens, we should get a non-zero cost
    $costs = $calculator->calculate(
        'openai',
        'gpt-4o-mini',
        100, // 100 prompt tokens
        50   // 50 completion tokens
    );

    // Cost in cents = (tokens * price_cents) / 1,000,000
    // 100 tokens at 15 cents/1M = 100 * 15 / 1,000,000 = 0.0015 cents
    expect($costs['prompt_cost'])->toEqual(0.0015)
        ->and($costs['completion_cost'])->toEqual(0.003)
        ->and($costs['total_cost_in_cents'])->toBeGreaterThan(0.004)
        ->and($costs['total_cost_in_cents'])->toBeLessThan(0.005)
        ->and($costs['total_cost_in_cents'])->toBeGreaterThan(0);
});

it('should calculate costs with openai pricing tiers', function () {
    $calculator = new CostCalculator;

    // Standard tier: gpt-4o input=250, output=1000 cents per 1M tokens
    $standardCosts = $calculator->calculate(
        'openai',
        'gpt-4o',
        1000000, // 1M tokens
        1000000, // 1M tokens
        0,
        PricingTier::Standard
    );

    expect($standardCosts['prompt_cost'])->toEqual(250.0)
        ->and($standardCosts['completion_cost'])->toEqual(1000.0);

    // Batch tier: gpt-4o input=125, output=500 cents per 1M tokens
    $batchCosts = $calculator->calculate(
        'openai',
        'gpt-4o',
        1000000,
        1000000,
        0,
        PricingTier::Batch
    );

    expect($batchCosts['prompt_cost'])->toEqual(125.0)
        ->and($batchCosts['completion_cost'])->toEqual(500.0);

    // Batch should be cheaper than Standard
    expect($batchCosts['total_cost_in_cents'])->toBeLessThan($standardCosts['total_cost_in_cents']);
});

it('should calculate costs with pricing tier as string', function () {
    $calculator = new CostCalculator;

    $costs = $calculator->calculate(
        'openai',
        'gpt-4o',
        1000000,
        1000000,
        0,
        'batch'
    );

    expect($costs['prompt_cost'])->toEqual(125.0)
        ->and($costs['completion_cost'])->toEqual(500.0);
});

it('uses standard tier as default for openai', function () {
    $calculator = new CostCalculator;

    // Without specifying tier, should use standard
    $costsDefault = $calculator->calculate(
        'openai',
        'gpt-4o',
        1000000,
        1000000
    );

    // Explicitly specifying standard tier
    $costsStandard = $calculator->calculate(
        'openai',
        'gpt-4o',
        1000000,
        1000000,
        0,
        PricingTier::Standard
    );

    expect($costsDefault['total_cost_in_cents'])->toEqual($costsStandard['total_cost_in_cents']);
});

it('should get cost per token with pricing tier', function () {
    $calculator = new CostCalculator;

    $standardCost = $calculator->getCostPerToken('openai', 'gpt-4o', PricingTier::Standard);
    $batchCost = $calculator->getCostPerToken('openai', 'gpt-4o', PricingTier::Batch);

    expect($standardCost)->not->toBeNull()
        ->and($batchCost)->not->toBeNull()
        ->and($standardCost['input_per_token'])->toEqual(0.00025)
        ->and($batchCost['input_per_token'])->toEqual(0.000125);
});
