<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Spectra\Http\Controllers\Api\CostsController;
use Spectra\Http\Controllers\Api\RequestsController;
use Spectra\Http\Controllers\Api\StatsController;
use Spectra\Integrations\OpenTelemetry\DefaultSpanBuilder;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;
use Spectra\Support\StatsAggregator;

/**
 * Apps may opt into immutable dates with Date::use(CarbonImmutable::class).
 * Everything the package type-hints must then accept CarbonImmutable, which
 * is not an Illuminate\Support\Carbon.
 */
beforeEach(function () {
    Date::use(CarbonImmutable::class);
});

afterEach(function () {
    Date::useDefault();
});

it('builds a date range from the request helpers', function () {
    expect(now())->toBeInstanceOf(CarbonImmutable::class);

    foreach (['today', 'week', 'month', 'year'] as $period) {
        $range = DateRange::fromRequest(new Request(['period' => $period]));

        expect($range->start)->toBeInstanceOf(CarbonImmutable::class)
            ->and($range->end)->toBeNull();
    }

    $custom = DateRange::fromRequest(new Request([
        'period' => 'custom',
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]));

    expect($custom->start?->format('Y-m-d'))->toBe('2026-01-01')
        ->and($custom->end?->format('Y-m-d'))->toBe('2026-01-31');

    expect(DateRange::fromRequest(new Request(['period' => 'all'])))
        ->start->toBeNull()
        ->end->toBeNull();
});

it('serves the dashboard endpoints', function () {
    SpectraRequest::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'model_type' => 'text',
        'status_code' => 200,
        'total_cost_in_cents' => 100,
        'latency_ms' => 120,
        'prompt_tokens' => 100,
        'completion_tokens' => 50,
        'created_at' => now(),
    ]);

    $request = new Request(['period' => 'month']);

    foreach ([StatsController::class, CostsController::class, RequestsController::class] as $controller) {
        $response = app()->call([app($controller), '__invoke'], ['request' => $request]);

        expect($response->toArray())->toBeArray()->not->toBeEmpty();
    }
});

it('exports span timestamps instead of silently zeroing them', function () {
    $model = SpectraRequest::factory()->create([
        'created_at' => now(),
        'completed_at' => now()->addMilliseconds(1500),
    ]);

    expect($model->fresh()->created_at)->toBeInstanceOf(CarbonImmutable::class);

    $span = (new DefaultSpanBuilder)->build([
        'id' => 'req-immutable',
        'trace_id' => '550e8400-e29b-41d4-a716-446655440000',
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'model_type' => 'text',
        'endpoint' => '/v1/chat/completions',
        'status_code' => 200,
        'started_at' => $model->fresh()->created_at,
        'completed_at' => $model->fresh()->completed_at,
    ]);

    expect($span['startTimeUnixNano'])->not->toBe('0')
        ->and($span['endTimeUnixNano'])->not->toBe('0');
});

it('rebuilds daily stats', function () {
    SpectraRequest::factory()->create(['created_at' => now()->subDay()]);

    expect(app(StatsAggregator::class)->rebuildForDate(now()->subDay()))->toBe(1)
        ->and(app(StatsAggregator::class)->rebuildYesterday())->toBe(1);
});
