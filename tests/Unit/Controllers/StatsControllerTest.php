<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Spectra\Http\Controllers\Api\StatsController;
use Spectra\Models\SpectraRequest;

/**
 * The stats dashboard endpoint: latency by model type, the calls recorded at
 * $0 for want of a price, and the date range filters.
 */
it('averages latency and sums cost per model type', function () {
    config()->set('spectra.dashboard.layout', 'full');

    SpectraRequest::factory()
        ->count(3)
        ->state(new Sequence(
            ['model' => 'gpt-4o', 'model_type' => 'text', 'latency_ms' => 120, 'total_cost_in_cents' => 125],
            ['model' => 'gpt-4o-mini', 'model_type' => 'text', 'latency_ms' => 280, 'total_cost_in_cents' => 75],
            ['model' => 'tts-1', 'model_type' => 'tts', 'latency_ms' => 940, 'total_cost_in_cents' => 210],
        ))
        ->create();

    $json = app()->call([app(StatsController::class), '__invoke'], ['request' => new Request(['period' => 'month'])])->toArray();

    $rows = collect($json['latency_by_model_type'])->keyBy('model_type');

    expect($rows['text']['count'])->toBe(2)
        ->and($rows['text']['avg_latency'])->toEqual(200)
        ->and($rows['tts']['count'])->toBe(1)
        ->and($rows['tts']['avg_latency'])->toEqual(940)
        ->and((float) $json['cost_by_model_type']['text'])->toEqualWithDelta(200.0, 0.0001)
        ->and((float) $json['cost_by_model_type']['tts'])->toEqualWithDelta(210.0, 0.0001)
        ->and((float) $json['cost_by_model_type']['image'])->toEqualWithDelta(0.0, 0.0001);
});

it('counts the successful calls of models without a price and names the models', function () {
    config()->set('spectra.dashboard.layout', 'full');

    SpectraRequest::factory()
        ->count(6)
        ->state(new Sequence(
            // Two calls to a fine-tune the catalog doesn't know, recorded at $0
            ['provider' => 'openai', 'model' => 'ft:gpt-4o-mini:acme', 'total_cost_in_cents' => 0],
            ['provider' => 'openai', 'model' => 'ft:gpt-4o-mini:acme', 'total_cost_in_cents' => 0],
            // A community model without a price, failed: not spend that went missing
            ['provider' => 'replicate', 'model' => 'someone/some-model', 'total_cost_in_cents' => 0, 'status_code' => 500],
            // A known model at $0 (nothing billed) and a priced call
            ['provider' => 'openai', 'model' => 'gpt-4o', 'total_cost_in_cents' => 0],
            ['provider' => 'openai', 'model' => 'gpt-4o', 'total_cost_in_cents' => 125],
            // Ollama has no catalog on purpose
            ['provider' => 'ollama', 'model' => 'llama3', 'total_cost_in_cents' => 0],
        ))
        ->create();

    $json = app()->call([app(StatsController::class), '__invoke'], ['request' => new Request(['period' => 'month'])])->toArray();

    expect($json['unpriced']['request_count'])->toBe(2)
        ->and($json['unpriced']['models'])->toBe(['ft:gpt-4o-mini:acme']);
});

it('counts only the requests inside a custom date range', function () {
    config()->set('spectra.dashboard.layout', 'full');
    $this->travelTo('2026-02-12 12:00:00');

    SpectraRequest::factory()
        ->count(2)
        ->state(new Sequence(
            ['model' => 'gpt-4o', 'model_type' => 'text', 'latency_ms' => 100, 'created_at' => Date::parse('2026-02-10 10:00:00')],
            ['model' => 'gpt-4o-mini', 'model_type' => 'text', 'latency_ms' => 200, 'created_at' => Date::parse('2026-02-12 10:00:00')],
        ))
        ->create();

    $controller = app(StatsController::class);
    $response = app()->call($controller->__invoke(...), ['request' => new Request([
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
});

it('counts requests of every date when the period is all', function () {
    config()->set('spectra.dashboard.layout', 'full');
    $this->travelTo('2026-02-12 12:00:00');

    SpectraRequest::factory()
        ->count(2)
        ->state(new Sequence(
            ['model' => 'old-model', 'model_type' => 'text', 'latency_ms' => 150, 'created_at' => Date::parse('2025-01-10 10:00:00')],
            ['model' => 'new-model', 'model_type' => 'text', 'latency_ms' => 250, 'created_at' => Date::parse('2026-02-10 10:00:00')],
        ))
        ->create();

    $controller = app(StatsController::class);
    $response = app()->call($controller->__invoke(...), ['request' => new Request([
        'period' => 'all',
    ])]);
    $json = $response->toArray();

    expect($json['total_requests'])->toBe(2)
        ->and($json['recent_requests'])->toHaveCount(2)
        ->and(collect($json['recent_requests'])->pluck('model')->all())->toContain('old-model');
});
