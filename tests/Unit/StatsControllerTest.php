<?php

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Spectra\Http\Controllers\Api\StatsController;
use Spectra\Models\SpectraRequest;

it('returns average latency grouped by model type in stats endpoint', function () {
    config()->set('spectra.dashboard.layout', 'full');

    SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'model_type' => 'text',
        'latency_ms' => 120,
        'total_cost_in_cents' => 125,
        'status_code' => 200,
        'created_at' => now(),
    ]);

    SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'model_type' => 'text',
        'latency_ms' => 280,
        'total_cost_in_cents' => 75,
        'status_code' => 200,
        'created_at' => now(),
    ]);

    SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'tts-1',
        'model_type' => 'tts',
        'latency_ms' => 940,
        'total_cost_in_cents' => 210,
        'status_code' => 200,
        'created_at' => now(),
    ]);

    $controller = app(StatsController::class);
    $response = app()->call([$controller, '__invoke'], ['request' => new Request(['period' => 'month'])]);
    $json = $response->toArray();

    expect($json)->toHaveKey('latency_by_model_type');
    expect($json)->toHaveKey('cost_by_model_type');

    $rows = collect($json['latency_by_model_type'])->keyBy('model_type');

    expect($rows->has('text'))->toBeTrue();
    expect($rows['text']['label'])->toBe('Text');
    expect($rows['text']['count'])->toBe(2);
    expect($rows['text']['avg_latency'])->toEqual(200);

    expect($rows->has('tts'))->toBeTrue();
    expect($rows['tts']['label'])->toBe('Text-to-Speech');
    expect($rows['tts']['count'])->toBe(1);
    expect($rows['tts']['avg_latency'])->toEqual(940);

    expect((float) $json['cost_by_model_type']['text'])->toBe(200.0);
    expect((float) $json['cost_by_model_type']['tts'])->toBe(210.0);
    expect((float) $json['cost_by_model_type']['image'])->toBe(0.0);
    expect((float) $json['cost_by_model_type']['video'])->toBe(0.0);
    expect((float) $json['cost_by_model_type']['stt'])->toBe(0.0);
});

it('filters stats by custom date range', function () {
    config()->set('spectra.dashboard.layout', 'full');
    Carbon::setTestNow(Carbon::parse('2026-02-12 12:00:00'));

    try {
        SpectraRequest::create([
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'model_type' => 'text',
            'latency_ms' => 100,
            'status_code' => 200,
            'created_at' => Carbon::parse('2026-02-10 10:00:00'),
        ]);

        SpectraRequest::create([
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'model_type' => 'text',
            'latency_ms' => 200,
            'status_code' => 200,
            'created_at' => Carbon::parse('2026-02-12 10:00:00'),
        ]);

        $controller = app(StatsController::class);
        $response = app()->call([$controller, '__invoke'], ['request' => new Request([
            'period' => 'custom',
            'start_date' => '2026-02-10',
            'end_date' => '2026-02-10',
        ])]);
        $json = $response->toArray();

        expect($json['total_requests'])->toBe(1);
        expect($json['avg_latency'])->toEqual(100);
        expect($json['requests_by_date'])->toHaveCount(1);
        expect($json['requests_by_date'][0]['date'])->toBe('2026-02-10');
        expect($json['recent_requests'])->toHaveCount(1);
        expect($json['recent_requests'][0]['model'])->toBe('gpt-4o');
    } finally {
        Carbon::setTestNow();
    }
});

it('returns all-time stats when period is all', function () {
    config()->set('spectra.dashboard.layout', 'full');
    Carbon::setTestNow(Carbon::parse('2026-02-12 12:00:00'));

    try {
        SpectraRequest::create([
            'provider' => 'openai',
            'model' => 'old-model',
            'model_type' => 'text',
            'latency_ms' => 150,
            'status_code' => 200,
            'created_at' => Carbon::parse('2025-01-10 10:00:00'),
        ]);

        SpectraRequest::create([
            'provider' => 'openai',
            'model' => 'new-model',
            'model_type' => 'text',
            'latency_ms' => 250,
            'status_code' => 200,
            'created_at' => Carbon::parse('2026-02-10 10:00:00'),
        ]);

        $controller = app(StatsController::class);
        $response = app()->call([$controller, '__invoke'], ['request' => new Request([
            'period' => 'all',
        ])]);
        $json = $response->toArray();

        expect($json['total_requests'])->toBe(2);
        expect($json['recent_requests'])->toHaveCount(2);
        expect(collect($json['recent_requests'])->pluck('model')->all())->toContain('old-model');
    } finally {
        Carbon::setTestNow();
    }
});
