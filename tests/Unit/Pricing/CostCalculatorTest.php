<?php

declare(strict_types=1);

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

it('calculates per-search cost for rerank models', function () {
    $calculator = new CostCalculator;

    // Cohere rerank-v3.5 is billed per search at 0.2 cents/search.
    $cost = $calculator->calculateBySearches('cohere', 'rerank-v3.5', 1);

    expect($cost['total_cost_in_cents'])->toEqual(0.2);

    $cost10 = $calculator->calculateBySearches('cohere', 'rerank-v3.5', 10);
    expect($cost10['total_cost_in_cents'])->toEqual(2.0);
});

it('exposes the search pricing unit for rerank models', function () {
    $calculator = new CostCalculator;

    expect($calculator->getPricingUnit('cohere', 'rerank-v3.5'))->toEqual('search');
});

it('calculates per-image cost', function () {
    $calculator = new CostCalculator;

    // dall-e-3 standard is 4 cents/image.
    $cost = $calculator->calculateByImages('openai', 'dall-e-3', 3);

    expect($cost['total_cost_in_cents'])->toEqual(12.0);
});

it('calculates per-minute audio cost', function () {
    $calculator = new CostCalculator;

    // whisper-1 is 0.6 cents/minute; 120 seconds = 2 minutes = 1.2 cents.
    $cost = $calculator->calculateByDuration('openai', 'whisper-1', 120.0);

    expect($cost['total_cost_in_cents'])->toEqual(1.2);
});

it('calculates per-second video cost', function () {
    $calculator = new CostCalculator;

    // sora-2 is 10 cents/second.
    $cost = $calculator->calculateByDurationSeconds('openai', 'sora-2', 5.0);

    expect($cost['total_cost_in_cents'])->toEqual(50.0);
});

it('returns zero per-unit cost for unknown models', function () {
    $calculator = new CostCalculator;

    expect($calculator->calculateBySearches('unknown', 'nope')['total_cost_in_cents'])->toEqual(0.0)
        ->and($calculator->calculateByImages('unknown', 'nope', 5)['total_cost_in_cents'])->toEqual(0.0);
});

it('prices the newly added openai gpt-5.4 / 5.5 models', function () {
    $calculator = new CostCalculator;

    // gpt-5.5 standard: $5/MTok in, $30/MTok out → 1M+1M = 500 + 3000 cents.
    $gpt55 = $calculator->calculate('openai', 'gpt-5.5', 1_000_000, 1_000_000, 0, PricingTier::Standard);
    expect($gpt55['total_cost_in_cents'])->toEqual(3500);

    // gpt-5.4 standard: $2.50 / $15.
    $gpt54 = $calculator->calculate('openai', 'gpt-5.4', 1_000_000, 1_000_000, 0, PricingTier::Standard);
    expect($gpt54['total_cost_in_cents'])->toEqual(1750);

    // gpt-5.4-mini priority tier: $1.50 / $9.
    $miniPriority = $calculator->calculate('openai', 'gpt-5.4-mini', 1_000_000, 1_000_000, 0, PricingTier::Priority);
    expect($miniPriority['total_cost_in_cents'])->toEqual(1050);

    // gpt-5.5-pro standard: $30 / $180.
    $pro = $calculator->calculate('openai', 'gpt-5.5-pro', 1_000_000, 1_000_000, 0, PricingTier::Standard);
    expect($pro['total_cost_in_cents'])->toEqual(21000);
});

it('prices the newly added xai grok-4.x models', function () {
    $calculator = new CostCalculator;

    // grok-4.3: $1.25/MTok in, $2.50/MTok out → 1M+1M = 125 + 250 cents.
    $grok = $calculator->calculate('xai', 'grok-4.3', 1_000_000, 1_000_000);
    expect($grok['total_cost_in_cents'])->toEqual(375);

    // Cached input billed at $0.20/MTok: 1M cached + 0 output = 20 cents.
    $cached = $calculator->calculate('xai', 'grok-4.3', 1_000_000, 0, 1_000_000);
    expect($cached['total_cost_in_cents'])->toEqual(20);
});

it('prices the newly added google gemini 3.x models', function () {
    $calculator = new CostCalculator;

    // gemini-3.5-flash standard: $1.50 in / $9 out → 150 + 900.
    $flash = $calculator->calculate('google', 'gemini-3.5-flash', 1_000_000, 1_000_000, 0, PricingTier::Standard);
    expect($flash['total_cost_in_cents'])->toEqual(1050);

    // gemini-3.1-pro-preview priority: $3.60 in / $21.60 out.
    $pro = $calculator->calculate('google', 'gemini-3.1-pro-preview', 1_000_000, 1_000_000, 0, PricingTier::Priority);
    expect($pro['total_cost_in_cents'])->toEqual(2520);

    // gemini-3.1-flash-lite standard: $0.25 in / $1.50 out.
    $lite = $calculator->calculate('google', 'gemini-3.1-flash-lite', 1_000_000, 1_000_000, 0, PricingTier::Standard);
    expect($lite['total_cost_in_cents'])->toEqual(175);

    // gemini-embedding-2: $0.20 / 1M input.
    $embed = $calculator->calculate('google', 'gemini-embedding-2', 1_000_000, 0, 0, PricingTier::Standard);
    expect($embed['total_cost_in_cents'])->toEqual(20);
});

it('prices the updated mistral models', function () {
    $calculator = new CostCalculator;

    // Mistral Medium 3.5: $1.50 in / $7.50 out.
    $medium = $calculator->calculate('mistral', 'mistral-medium-latest', 1_000_000, 1_000_000);
    expect($medium['total_cost_in_cents'])->toEqual(900);

    // Mistral Small 4: $0.15 / $0.60.
    $small = $calculator->calculate('mistral', 'mistral-small-latest', 1_000_000, 1_000_000);
    expect($small['total_cost_in_cents'])->toEqual(75);

    // mistral-embed corrected to $0.10 / 1M.
    $embed = $calculator->calculate('mistral', 'mistral-embed', 1_000_000, 0);
    expect($embed['total_cost_in_cents'])->toEqual(10);

    // Voxtral Mini TTS: $0.016 per 1k chars → 1.6 cents for 1000 chars.
    $tts = $calculator->calculateByCharacters('mistral', 'voxtral-mini-tts-latest', 1000);
    expect($tts['total_cost_in_cents'])->toEqual(1.6);
});
