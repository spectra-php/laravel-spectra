<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Spectra\Data\TokenMetrics;
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

it('prices the models added in the 2026-11-01 review', function () {
    $calculator = new CostCalculator;

    // gpt-6-rosalind: $15 / $75, and $30 / $112.50 above 272K input tokens.
    $rosalind = $calculator->calculate('openai', 'gpt-6-rosalind', 100_000, 100_000);
    expect($rosalind['total_cost_in_cents'])->toEqualWithDelta(900, 1e-9);

    $rosalindLong = $calculator->calculate('openai', 'gpt-6-rosalind', 300_000, 100_000);
    expect($rosalindLong['total_cost_in_cents'])->toEqualWithDelta(300_000 * 3000 / 1e6 + 100_000 * 11250 / 1e6, 1e-9);

    // gpt-6-astra-law: $10 / $50 (promotional).
    $law = $calculator->calculate('openai', 'gpt-6-astra-law', 100_000, 100_000);
    expect($law['total_cost_in_cents'])->toEqualWithDelta(600, 1e-9);

    // gemini-robotics-er-2-streaming-preview: $1 / $5, doubling on 2027-01-01.
    $tokens = new TokenMetrics(promptTokens: 1_000_000, completionTokens: 1_000_000);
    expect($calculator->forTokens('google', 'gemini-robotics-er-2-streaming-preview', $tokens, at: CarbonImmutable::parse('2026-11-01'))['total_cost_in_cents'])->toEqual(600)
        ->and($calculator->forTokens('google', 'gemini-robotics-er-2-streaming-preview', $tokens, at: CarbonImmutable::parse('2027-01-01'))['total_cost_in_cents'])->toEqual(1200);

    // Voxtral 3: TTS $13 / 1M chars; transcription per minute.
    expect($calculator->calculateByCharacters('mistral', 'voxtral-mini-tts-3-0', 1_000_000)['total_cost_in_cents'])->toEqual(1300)
        ->and($calculator->calculateByDuration('mistral', 'voxtral-mini-transcribe-latest', 600)['total_cost_in_cents'])->toEqualWithDelta(3.5, 1e-9)
        ->and($calculator->calculateByDuration('mistral', 'voxtral-mini-transcribe-realtime-3', 600)['total_cost_in_cents'])->toEqualWithDelta(8.0, 1e-9)
        ->and($calculator->calculateByDuration('mistral', 'voxtral-small-transcribe-3-0', 600)['total_cost_in_cents'])->toEqualWithDelta(4.0, 1e-9);
});
