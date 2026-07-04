<?php

declare(strict_types=1);

use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Pricing\RequestCostCalculator;
use Spectra\Support\StatsAggregator;
use Spectra\Support\Tracking\RequestContext;

it('bills per-search models using the tracked search count', function () {
    $calculator = app(RequestCostCalculator::class);

    $context = new RequestContext([
        'provider' => 'cohere',
        'model' => 'rerank-v3.5',
        'search_count' => 3,
    ]);

    $cost = $calculator->forContext($context);

    expect($cost['total_cost_in_cents'])->toEqualWithDelta(0.6, 0.0001);
});

it('does not bill per-search models when no search was processed', function () {
    $calculator = app(RequestCostCalculator::class);

    // A failed request never goes through response processing, so no
    // search count is recorded — it must not be billed as one search.
    $context = new RequestContext([
        'provider' => 'cohere',
        'model' => 'rerank-v3.5',
    ]);

    $cost = $calculator->forContext($context);

    expect($cost['total_cost_in_cents'])->toBe(0.0);
});

it('includes reasoning tokens in aggregated daily stats', function () {
    SpectraDailyStat::record(
        date: now()->format('Y-m-d'),
        provider: 'openai',
        model: 'o3',
        trackableType: null,
        trackableId: null,
        successful: true,
        promptTokens: 100,
        completionTokens: 50,
        cost: 500,
        latencyMs: 250,
        reasoningTokens: 4200,
    );

    $stats = SpectraDailyStat::getAggregatedStats(now()->startOfDay(), now()->endOfDay());

    expect($stats['total_reasoning_tokens'])->toBe(4200);
});

it('rejects rebuild-stats when --from is after --to', function () {
    $this->artisan('spectra:rebuild-stats', ['--from' => '2026-06-30', '--to' => '2026-06-01'])
        ->expectsOutputToContain('must be before')
        ->assertFailed();
});

it('rebuilds stats for an explicit date range', function () {
    SpectraRequest::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'status_code' => 200,
        'total_cost_in_cents' => 100,
        'created_at' => now(),
    ]);

    $this->artisan('spectra:rebuild-stats', [
        '--from' => now()->subDay()->format('Y-m-d'),
        '--to' => now()->addDay()->format('Y-m-d'),
    ])->assertSuccessful();

    expect(app(StatsAggregator::class))->toBeInstanceOf(StatsAggregator::class)
        ->and(SpectraDailyStat::query()->sum('request_count'))->toEqual(1);
});
