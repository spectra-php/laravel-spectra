<?php

use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Spectra\Http\Controllers\Api\StatsController;
use Spectra\Models\SpectraRequest;

it('returns average latency grouped by model type in stats endpoint', function () {
    config()->set('spectra.dashboard.layout', 'full');

    SpectraRequest::factory()
        ->count(3)
        ->state(new Sequence(
            ['model' => 'gpt-4o', 'model_type' => 'text', 'latency_ms' => 120, 'total_cost_in_cents' => 125],
            ['model' => 'gpt-4o-mini', 'model_type' => 'text', 'latency_ms' => 280, 'total_cost_in_cents' => 75],
            ['model' => 'tts-1', 'model_type' => 'tts', 'latency_ms' => 940, 'total_cost_in_cents' => 210],
        ))
        ->create();

    $controller = app(StatsController::class);
    $response = app()->call([$controller, '__invoke'], ['request' => new Request(['period' => 'month'])]);
    $json = $response->toArray();

    $rows = collect($json['latency_by_model_type'])->keyBy('model_type');

    expect($json)->toHaveKey('latency_by_model_type')
        ->and($json)->toHaveKey('cost_by_model_type')
        ->and($rows->has('text'))->toBeTrue()
        ->and($rows['text']['label'])->toBe('Text')
        ->and($rows['text']['count'])->toBe(2)
        ->and($rows['text']['avg_latency'])->toEqual(200)
        ->and($rows->has('tts'))->toBeTrue()
        ->and($rows['tts']['label'])->toBe('Text-to-Speech')
        ->and($rows['tts']['count'])->toBe(1)
        ->and($rows['tts']['avg_latency'])->toEqual(940)
        ->and((float) $json['cost_by_model_type']['text'])->toBe(200.0)
        ->and((float) $json['cost_by_model_type']['tts'])->toBe(210.0)
        ->and((float) $json['cost_by_model_type']['image'])->toBe(0.0)
        ->and((float) $json['cost_by_model_type']['video'])->toBe(0.0)
        ->and((float) $json['cost_by_model_type']['stt'])->toBe(0.0);
});

it('filters stats by custom date range', function () {
    config()->set('spectra.dashboard.layout', 'full');
    Carbon::setTestNow(Carbon::parse('2026-02-12 12:00:00'));

    try {
        SpectraRequest::factory()
            ->count(2)
            ->state(new Sequence(
                ['model' => 'gpt-4o', 'model_type' => 'text', 'latency_ms' => 100, 'created_at' => Carbon::parse('2026-02-10 10:00:00')],
                ['model' => 'gpt-4o-mini', 'model_type' => 'text', 'latency_ms' => 200, 'created_at' => Carbon::parse('2026-02-12 10:00:00')],
            ))
            ->create();

        $controller = app(StatsController::class);
        $response = app()->call([$controller, '__invoke'], ['request' => new Request([
            'period' => 'custom',
            'start_date' => '2026-02-10',
            'end_date' => '2026-02-10',
        ])]);
        $json = $response->toArray();

        expect($json['total_requests'])->toBe(1)
            ->and($json['avg_latency'])->toEqual(100)
            ->and($json['requests_by_date'])->toHaveCount(1)
            ->and($json['requests_by_date'][0]['date'])->toBe('2026-02-10')
            ->and($json['recent_requests'])->toHaveCount(1)
            ->and($json['recent_requests'][0]['model'])->toBe('gpt-4o');
    } finally {
        Carbon::setTestNow();
    }
});

it('returns all-time stats when period is all', function () {
    config()->set('spectra.dashboard.layout', 'full');
    Carbon::setTestNow(Carbon::parse('2026-02-12 12:00:00'));

    try {
        SpectraRequest::factory()
            ->count(2)
            ->state(new Sequence(
                ['model' => 'old-model', 'model_type' => 'text', 'latency_ms' => 150, 'created_at' => Carbon::parse('2025-01-10 10:00:00')],
                ['model' => 'new-model', 'model_type' => 'text', 'latency_ms' => 250, 'created_at' => Carbon::parse('2026-02-10 10:00:00')],
            ))
            ->create();

        $controller = app(StatsController::class);
        $response = app()->call([$controller, '__invoke'], ['request' => new Request([
            'period' => 'all',
        ])]);
        $json = $response->toArray();

        expect($json['total_requests'])->toBe(2)
            ->and($json['recent_requests'])->toHaveCount(2)
            ->and(collect($json['recent_requests'])->pluck('model')->all())->toContain('old-model');
    } finally {
        Carbon::setTestNow();
    }
});
