<?php

use Illuminate\Database\Eloquent\Factories\Sequence;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;
use Spectra\Support\StatsAggregator;
use Workbench\App\Models\User;

it('should record stats when a request is created', function () {
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

    $stat = SpectraDailyStat::where('provider', 'openai')
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

it('should increment stats for multiple requests', function () {
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

    $stat = SpectraDailyStat::where('provider', 'anthropic')
        ->where('model', 'claude-3')
        ->whereNull('trackable_type')
        ->first();

    expect($stat)->not->toBeNull()
        ->and($stat->request_count)->toBe(3)
        ->and($stat->total_tokens)->toBe(450)
        ->and($stat->total_cost_in_cents)->toBe(600.0);
});

it('should track failed requests separately', function () {
    $aggregator = app(StatsAggregator::class);

    SpectraRequest::factory()
        ->count(2)
        ->state(new Sequence(
            ['model' => 'gpt-4-failed-test'],
            ['model' => 'gpt-4-failed-test', 'status_code' => 500],
        ))
        ->create()
        ->each(fn ($request) => $aggregator->recordRequest($request));

    $stat = SpectraDailyStat::where('model', 'gpt-4-failed-test')
        ->whereNull('trackable_type')
        ->first();

    expect($stat)->not->toBeNull()
        ->and($stat->request_count)->toBe(2)
        ->and($stat->successful_count)->toBe(1)
        ->and($stat->failed_count)->toBe(1)
        ->and($stat->successRate)->toBe(50.0);
});

it('should track per-user stats separately', function () {
    $user = User::create([
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

    // Should have global stat
    $globalStat = SpectraDailyStat::where('model', 'gpt-4-user-test')
        ->whereNull('trackable_type')
        ->first();

    expect($globalStat)->not->toBeNull()
        ->and($globalStat->request_count)->toBe(1);

    // Should have user-specific stat
    $userStat = SpectraDailyStat::where('model', 'gpt-4-user-test')
        ->where('trackable_type', User::class)
        ->where('trackable_id', $user->id)
        ->first();

    expect($userStat)->not->toBeNull()
        ->and($userStat->request_count)->toBe(1);
});

it('should get aggregated stats for a period', function () {
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
        now()->startOfDay(),
        now()->endOfDay(),
        'openai',
        'gpt-4-period-test'
    );

    expect($stats['total_requests'])->toBe(5)
        ->and($stats['total_tokens'])->toBe(750)
        ->and($stats['total_cost_in_cents'])->toBe(1000.0)
        ->and($stats['success_rate'])->toBe(100.0);
});

it('should get daily breakdown', function () {
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

    expect($breakdown)->toBeArray()
        ->and(count($breakdown))->toBeGreaterThanOrEqual(1);

    $todayData = collect($breakdown)->firstWhere('date', now()->format('Y-m-d'));
    expect($todayData)->not->toBeNull()
        ->and($todayData['requests'])->toBeGreaterThanOrEqual(1);
});

it('should record model_type in daily stats', function () {
    $aggregator = app(StatsAggregator::class);

    $request = SpectraRequest::factory()->create([
        'model' => 'dall-e-3',
        'model_type' => 'image',
        'response' => json_encode(['data' => []]),
        'image_count' => 2,
        'total_cost_in_cents' => 400,
    ]);

    $aggregator->recordRequest($request);

    $stat = SpectraDailyStat::where('provider', 'openai')
        ->where('model', 'dall-e-3')
        ->where('model_type', 'image')
        ->whereNull('trackable_type')
        ->first();

    expect($stat)->not->toBeNull()
        ->and($stat->request_count)->toBe(1)
        ->and($stat->total_images)->toBe(2)
        ->and($stat->total_cost_in_cents)->toBe(400.0);
});

it('should record billing metrics for different model types', function () {
    $aggregator = app(StatsAggregator::class);

    // TTS request
    $ttsRequest = SpectraRequest::factory()->create([
        'model' => 'tts-1',
        'model_type' => 'tts',
        'response' => json_encode([]),
        'input_characters' => 500,
        'duration_seconds' => 12.5,
        'total_cost_in_cents' => 100,
    ]);

    $aggregator->recordRequest($ttsRequest);

    $stat = SpectraDailyStat::where('model', 'tts-1')
        ->where('model_type', 'tts')
        ->whereNull('trackable_type')
        ->first();

    expect($stat)->not->toBeNull()
        ->and($stat->total_input_characters)->toBe(500)
        ->and($stat->total_duration_seconds)->toBe(12.5);
});

it('should record video_count in daily stats', function () {
    $aggregator = app(StatsAggregator::class);

    $request = SpectraRequest::factory()->create([
        'model' => 'sora',
        'model_type' => 'video',
        'response' => json_encode(['data' => []]),
        'video_count' => 3,
        'total_cost_in_cents' => 600,
    ]);

    $aggregator->recordRequest($request);

    $stat = SpectraDailyStat::where('provider', 'openai')
        ->where('model', 'sora')
        ->where('model_type', 'video')
        ->whereNull('trackable_type')
        ->first();

    expect($stat)->not->toBeNull()
        ->and($stat->request_count)->toBe(1)
        ->and($stat->total_videos)->toBe(3)
        ->and($stat->total_cost_in_cents)->toBe(600.0);
});

it('should separate stats by model_type', function () {
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
        now()->startOfDay(),
        now()->endOfDay(),
        'openai',
        null,
        null,
        'text'
    );

    expect($textStats['total_requests'])->toBe(1)
        ->and($textStats['total_tokens'])->toBe(150);

    $imageStats = SpectraDailyStat::getAggregatedStats(
        now()->startOfDay(),
        now()->endOfDay(),
        'openai',
        null,
        null,
        'image'
    );

    expect($imageStats['total_requests'])->toBe(1)
        ->and($imageStats['total_images'])->toBe(1);
});

it('should get stats by provider', function () {
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
        now()->startOfDay(),
        now()->endOfDay()
    );

    expect($byProvider)->toBeArray();

    $providers = collect($byProvider)->pluck('provider')->toArray();
    expect($providers)->toContain('openai')
        ->and($providers)->toContain('anthropic');
});

it('should record reasoning tokens in daily stats', function () {
    $aggregator = app(StatsAggregator::class);

    $request = SpectraRequest::factory()->create([
        'model' => 'o3-mini',
        'response' => json_encode(['content' => 'reasoning result']),
        'prompt_tokens' => 50,
        'completion_tokens' => 20,
        'reasoning_tokens' => 150,
        'total_cost_in_cents' => 100,
        'latency_ms' => 300,
    ]);

    $aggregator->recordRequest($request);

    $stat = SpectraDailyStat::where('provider', 'openai')
        ->where('model', 'o3-mini')
        ->whereNull('trackable_type')
        ->first();

    expect($stat)->not->toBeNull()
        ->and($stat->total_reasoning_tokens)->toBe(150)
        ->and($stat->prompt_tokens)->toBe(50)
        ->and($stat->completion_tokens)->toBe(20);
});

it('should accumulate reasoning tokens across multiple requests', function () {
    $aggregator = app(StatsAggregator::class);

    SpectraRequest::factory()
        ->count(3)
        ->create([
            'model' => 'o3-mini',
            'response' => json_encode(['content' => 'test']),
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'reasoning_tokens' => 100,
            'total_cost_in_cents' => 50,
        ])
        ->each(fn ($request) => $aggregator->recordRequest($request));

    $stat = SpectraDailyStat::where('provider', 'openai')
        ->where('model', 'o3-mini')
        ->whereNull('trackable_type')
        ->first();

    expect($stat->total_reasoning_tokens)->toBe(300)
        ->and($stat->request_count)->toBe(3);
});
