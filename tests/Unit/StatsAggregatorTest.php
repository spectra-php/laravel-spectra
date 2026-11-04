<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Factories\Sequence;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;
use Spectra\Support\StatsAggregator;
use Workbench\App\Models\User;

/**
 * StatsAggregator writes each request into the global and per-trackable
 * daily stats, and SpectraDailyStat reads them back aggregated by period,
 * day, provider and model type.
 */
it('records counts, tokens and cost on the global daily stat for a request', function () {
    $aggregator = app(StatsAggregator::class);

    $request = SpectraRequest::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-4',
        'prompt_tokens' => 100,
        'completion_tokens' => 50,
        'total_cost_in_cents' => 500,
        'latency_ms' => 250,
    ]);

    $aggregator->recordRequest($request);

    $stat = SpectraDailyStat::query()->where('provider', 'openai')
        ->where('model', 'gpt-4')
        ->whereNull('trackable_type')
        ->first();

    expect($stat)->not->toBeNull()
        ->and($stat->request_count)->toBe(1)
        ->and($stat->successful_count)->toBe(1)
        ->and($stat->failed_count)->toBe(0)
        ->and($stat->prompt_tokens)->toBe(100)
        ->and($stat->completion_tokens)->toBe(50)
        ->and($stat->total_cost_in_cents)->toBe(500.0);
});

it('adds repeated requests for one model into a single daily row', function () {
    $aggregator = app(StatsAggregator::class);

    SpectraRequest::factory()
        ->count(3)
        ->create([
            'provider' => 'anthropic',
            'model' => 'claude-3',
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'total_cost_in_cents' => 200,
        ])
        ->each(fn ($request) => $aggregator->recordRequest($request));

    $stat = SpectraDailyStat::query()->where('provider', 'anthropic')
        ->where('model', 'claude-3')
        ->whereNull('trackable_type')
        ->first();

    expect($stat)->not->toBeNull()
        ->and($stat->request_count)->toBe(3)
        ->and($stat->total_tokens)->toBe(450)
        ->and($stat->total_cost_in_cents)->toBe(600.0);
});

it('counts a 500 response as failed and halves the success rate', function () {
    $aggregator = app(StatsAggregator::class);

    SpectraRequest::factory()
        ->count(2)
        ->state(new Sequence(
            ['model' => 'gpt-4-failed-test'],
            ['model' => 'gpt-4-failed-test', 'status_code' => 500],
        ))
        ->create()
        ->each(fn ($request) => $aggregator->recordRequest($request));

    $stat = SpectraDailyStat::query()->where('model', 'gpt-4-failed-test')
        ->whereNull('trackable_type')
        ->first();

    expect($stat)->not->toBeNull()
        ->and($stat->request_count)->toBe(2)
        ->and($stat->successful_count)->toBe(1)
        ->and($stat->failed_count)->toBe(1)
        ->and($stat->successRate)->toBe(50.0);
});

it('writes both a global and a per-user daily stat for a tracked request', function () {
    $user = User::query()->create([
        'name' => 'Test User',
        'email' => 'stats-test@example.com',
        'password' => 'password',
    ]);

    $aggregator = app(StatsAggregator::class);

    $request = SpectraRequest::factory()->create([
        'model' => 'gpt-4-user-test',
        'trackable_type' => User::class,
        'trackable_id' => $user->id,
        'total_cost_in_cents' => 100,
    ]);

    $aggregator->recordRequest($request);

    $globalStat = SpectraDailyStat::query()->where('model', 'gpt-4-user-test')
        ->whereNull('trackable_type')
        ->first();
    $userStat = SpectraDailyStat::query()->where('model', 'gpt-4-user-test')
        ->where('trackable_type', User::class)
        ->where('trackable_id', $user->id)
        ->first();

    expect($globalStat)->not->toBeNull()
        ->and($globalStat->request_count)->toBe(1)
        ->and($userStat)->not->toBeNull()
        ->and($userStat->request_count)->toBe(1);
});

it('aggregates requests, tokens, cost and success rate over a period', function () {
    $aggregator = app(StatsAggregator::class);

    SpectraRequest::factory()
        ->count(5)
        ->state(new Sequence(
            ['latency_ms' => 100],
            ['latency_ms' => 150],
            ['latency_ms' => 200],
            ['latency_ms' => 250],
            ['latency_ms' => 300],
        ))
        ->create([
            'model' => 'gpt-4-period-test',
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'total_cost_in_cents' => 200,
        ])
        ->each(fn ($request) => $aggregator->recordRequest($request));

    $stats = SpectraDailyStat::getAggregatedStats(
        today(),
        now()->endOfDay(),
        'openai',
        'gpt-4-period-test'
    );

    expect($stats['total_requests'])->toBe(5)
        ->and($stats['total_tokens'])->toBe(750)
        ->and($stats['total_cost_in_cents'])->toBe(1000.0)
        ->and($stats['success_rate'])->toBe(100.0);
});

it('includes the request count for today in the daily breakdown', function () {
    $aggregator = app(StatsAggregator::class);

    $aggregator->recordRequest(SpectraRequest::factory()->create([
        'model' => 'gpt-4-daily-test',
        'total_cost_in_cents' => 100,
    ]));

    $breakdown = SpectraDailyStat::getDailyBreakdown(
        now()->startOfMonth(),
        now()->endOfMonth(),
        'openai'
    );

    $todayData = collect($breakdown)->firstWhere('date', now()->format('Y-m-d'));

    expect($todayData)->not->toBeNull()
        ->and($todayData['requests'])->toBeGreaterThanOrEqual(1);
});

it('filters aggregated stats by model type', function () {
    $aggregator = app(StatsAggregator::class);

    SpectraRequest::factory()
        ->count(2)
        ->state(new Sequence(
            ['model' => 'gpt-4o', 'model_type' => 'text', 'prompt_tokens' => 100, 'completion_tokens' => 50, 'total_cost_in_cents' => 200],
            ['model' => 'dall-e-3', 'model_type' => 'image', 'image_count' => 1, 'total_cost_in_cents' => 400],
        ))
        ->create(['response' => json_encode([])])
        ->each(fn ($request) => $aggregator->recordRequest($request));

    $textStats = SpectraDailyStat::getAggregatedStats(
        today(),
        now()->endOfDay(),
        'openai',
        null,
        null,
        'text'
    );
    $imageStats = SpectraDailyStat::getAggregatedStats(
        today(),
        now()->endOfDay(),
        'openai',
        null,
        null,
        'image'
    );

    expect($textStats['total_requests'])->toBe(1)
        ->and($textStats['total_tokens'])->toBe(150)
        ->and($imageStats['total_requests'])->toBe(1)
        ->and($imageStats['total_images'])->toBe(1);
});

it('lists every provider with requests in the period', function () {
    $aggregator = app(StatsAggregator::class);

    SpectraRequest::factory()
        ->count(2)
        ->state(new Sequence(
            ['total_cost_in_cents' => 100],
            ['provider' => 'anthropic', 'model' => 'claude-3', 'total_cost_in_cents' => 150],
        ))
        ->create()
        ->each(fn ($request) => $aggregator->recordRequest($request));

    $byProvider = SpectraDailyStat::getStatsByProvider(
        today(),
        now()->endOfDay()
    );

    $providers = collect($byProvider)->pluck('provider')->toArray();

    expect($providers)->toContain('openai')
        ->and($providers)->toContain('anthropic');
});

it('replaces the end day on every rebuild instead of adding to it', function () {
    $this->travelTo('2026-03-10 12:00:00');
    SpectraRequest::factory()->create(['provider' => 'openai', 'model' => 'gpt-rebuild', 'created_at' => '2026-03-09 15:00:00']);
    $aggregator = app(StatsAggregator::class);

    $aggregator->rebuildYesterday();
    $aggregator->rebuildYesterday();

    $stat = SpectraDailyStat::query()->where('model', 'gpt-rebuild')->whereNull('trackable_type')->sole();

    expect($stat->request_count)->toBe(1);
});
