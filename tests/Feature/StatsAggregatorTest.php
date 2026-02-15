<?php

use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;
use Spectra\Support\StatsAggregator;
use Workbench\App\Models\User;

it('records stats when a request is created', function () {
    $aggregator = app(StatsAggregator::class);

    $request = SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'gpt-4',
        'response' => json_encode(['prompt' => 'test']),
        'prompt_tokens' => 100,
        'completion_tokens' => 50,
        'total_cost_in_cents' => 500,
        'latency_ms' => 250,
        'status_code' => 200,
        'created_at' => now(),
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

it('increments stats for multiple requests', function () {
    $aggregator = app(StatsAggregator::class);

    for ($i = 0; $i < 3; $i++) {
        $request = SpectraRequest::create([
            'provider' => 'anthropic',
            'model' => 'claude-3',
            'response' => json_encode(['prompt' => 'test']),
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'total_cost_in_cents' => 200,
            'status_code' => 200,
            'created_at' => now(),
        ]);

        $aggregator->recordRequest($request);
    }

    $stat = SpectraDailyStat::where('provider', 'anthropic')
        ->where('model', 'claude-3')
        ->whereNull('trackable_type')
        ->first();

    expect($stat)->not->toBeNull()
        ->and($stat->request_count)->toBe(3)
        ->and($stat->total_tokens)->toBe(450)
        ->and($stat->total_cost_in_cents)->toBe(600.0);
});

it('tracks failed requests separately', function () {
    $aggregator = app(StatsAggregator::class);

    // Successful request
    $aggregator->recordRequest(SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'gpt-4-failed-test',
        'response' => json_encode(['prompt' => 'test']),
        'status_code' => 200,
        'created_at' => now(),
    ]));

    // Failed request
    $aggregator->recordRequest(SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'gpt-4-failed-test',
        'response' => json_encode(['prompt' => 'test']),
        'status_code' => 500,
        'created_at' => now(),
    ]));

    $stat = SpectraDailyStat::where('model', 'gpt-4-failed-test')
        ->whereNull('trackable_type')
        ->first();

    expect($stat)->not->toBeNull()
        ->and($stat->request_count)->toBe(2)
        ->and($stat->successful_count)->toBe(1)
        ->and($stat->failed_count)->toBe(1)
        ->and($stat->successRate)->toBe(50.0);
});

it('tracks per-user stats separately', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'stats-test@example.com',
        'password' => 'password',
    ]);

    $aggregator = app(StatsAggregator::class);

    $request = SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'gpt-4-user-test',
        'trackable_type' => User::class,
        'trackable_id' => $user->id,
        'response' => json_encode(['prompt' => 'test']),
        'total_cost_in_cents' => 100,
        'status_code' => 200,
        'created_at' => now(),
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

    expect($userStat)->not->toBeNull();
    expect($userStat->request_count)->toBe(1);
});

it('can get aggregated stats for a period', function () {
    $aggregator = app(StatsAggregator::class);

    for ($i = 0; $i < 5; $i++) {
        $aggregator->recordRequest(SpectraRequest::create([
            'provider' => 'openai',
            'model' => 'gpt-4-period-test',
            'response' => json_encode(['prompt' => 'test']),
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'total_cost_in_cents' => 200,
            'latency_ms' => 100 + ($i * 50),
            'status_code' => 200,
            'created_at' => now(),
        ]));
    }

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

it('can get daily breakdown', function () {
    $aggregator = app(StatsAggregator::class);

    $aggregator->recordRequest(SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'gpt-4-daily-test',
        'response' => json_encode(['prompt' => 'test']),
        'total_cost_in_cents' => 100,
        'status_code' => 200,
        'created_at' => now(),
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

it('records model_type in daily stats', function () {
    $aggregator = app(StatsAggregator::class);

    $request = SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'dall-e-3',
        'model_type' => 'image',
        'response' => json_encode(['data' => []]),
        'image_count' => 2,
        'total_cost_in_cents' => 400,
        'status_code' => 200,
        'created_at' => now(),
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

it('records billing metrics for different model types', function () {
    $aggregator = app(StatsAggregator::class);

    // TTS request
    $ttsRequest = SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'tts-1',
        'model_type' => 'tts',
        'response' => json_encode([]),
        'input_characters' => 500,
        'duration_seconds' => 12.5,
        'total_cost_in_cents' => 100,
        'status_code' => 200,
        'created_at' => now(),
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

it('records video_count in daily stats', function () {
    $aggregator = app(StatsAggregator::class);

    $request = SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'sora',
        'model_type' => 'video',
        'response' => json_encode(['data' => []]),
        'video_count' => 3,
        'total_cost_in_cents' => 600,
        'status_code' => 200,
        'created_at' => now(),
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

it('separates stats by model_type', function () {
    $aggregator = app(StatsAggregator::class);

    // Text request
    $aggregator->recordRequest(SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'model_type' => 'text',
        'response' => json_encode([]),
        'prompt_tokens' => 100,
        'completion_tokens' => 50,
        'total_cost_in_cents' => 200,
        'status_code' => 200,
        'created_at' => now(),
    ]));

    // Image request (same provider, different model type)
    $aggregator->recordRequest(SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'dall-e-3',
        'model_type' => 'image',
        'response' => json_encode([]),
        'image_count' => 1,
        'total_cost_in_cents' => 400,
        'status_code' => 200,
        'created_at' => now(),
    ]));

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

it('can get stats by provider', function () {
    $aggregator = app(StatsAggregator::class);

    $aggregator->recordRequest(SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'gpt-4',
        'response' => json_encode(['prompt' => 'test']),
        'total_cost_in_cents' => 100,
        'status_code' => 200,
        'created_at' => now(),
    ]));

    $aggregator->recordRequest(SpectraRequest::create([
        'provider' => 'anthropic',
        'model' => 'claude-3',
        'response' => json_encode(['prompt' => 'test']),
        'total_cost_in_cents' => 150,
        'status_code' => 200,
        'created_at' => now(),
    ]));

    $byProvider = SpectraDailyStat::getStatsByProvider(
        now()->startOfDay(),
        now()->endOfDay()
    );

    expect($byProvider)->toBeArray();

    $providers = collect($byProvider)->pluck('provider')->toArray();
    expect($providers)->toContain('openai')
        ->and($providers)->toContain('anthropic');
});

it('records reasoning tokens in daily stats', function () {
    $aggregator = app(StatsAggregator::class);

    $request = SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'o3-mini',
        'response' => json_encode(['content' => 'reasoning result']),
        'prompt_tokens' => 50,
        'completion_tokens' => 20,
        'reasoning_tokens' => 150,
        'total_cost_in_cents' => 100,
        'latency_ms' => 300,
        'status_code' => 200,
        'created_at' => now(),
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

it('accumulates reasoning tokens across multiple requests', function () {
    $aggregator = app(StatsAggregator::class);

    for ($i = 0; $i < 3; $i++) {
        $request = SpectraRequest::create([
            'provider' => 'openai',
            'model' => 'o3-mini',
            'response' => json_encode(['content' => 'test']),
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'reasoning_tokens' => 100,
            'total_cost_in_cents' => 50,
            'status_code' => 200,
            'created_at' => now(),
        ]);

        $aggregator->recordRequest($request);
    }

    $stat = SpectraDailyStat::where('provider', 'openai')
        ->where('model', 'o3-mini')
        ->whereNull('trackable_type')
        ->first();

    expect($stat->total_reasoning_tokens)->toBe(300)
        ->and($stat->request_count)->toBe(3);
});
