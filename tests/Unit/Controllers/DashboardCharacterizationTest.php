<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Spectra\Http\Controllers\Api\ShowTrackableController;
use Spectra\Http\Controllers\Api\StatsController;
use Spectra\Models\SpectraRequest;

/**
 * Characterization tests locking the dashboard endpoint numbers across the
 * Queries/ consolidation — the refactor must not change any value here.
 */
function seedDashboardDataset(): void
{
    Carbon::setTestNow(Carbon::parse('2026-07-04 12:00:00'));

    $rows = [
        // Two successful gpt-4o requests for User#1
        ['provider' => 'openai', 'model' => 'gpt-4o', 'model_type' => 'text', 'status_code' => 200, 'total_cost_in_cents' => 100, 'latency_ms' => 100, 'prompt_tokens' => 100, 'completion_tokens' => 50, 'trackable_type' => 'App\\Models\\User', 'trackable_id' => '1'],
        ['provider' => 'openai', 'model' => 'gpt-4o', 'model_type' => 'text', 'status_code' => 201, 'total_cost_in_cents' => 200, 'latency_ms' => 300, 'prompt_tokens' => 10, 'completion_tokens' => 10, 'trackable_type' => 'App\\Models\\User', 'trackable_id' => '1'],
        // One failed claude request for User#1 — must never contribute cost/latency
        ['provider' => 'anthropic', 'model' => 'claude-x', 'model_type' => 'text', 'status_code' => 500, 'total_cost_in_cents' => 999, 'latency_ms' => 30000, 'prompt_tokens' => 5, 'completion_tokens' => 5, 'trackable_type' => 'App\\Models\\User', 'trackable_id' => '1'],
        // One successful image request for User#2
        ['provider' => 'openai', 'model' => 'gpt-image-1', 'model_type' => 'image', 'status_code' => 200, 'total_cost_in_cents' => 50, 'latency_ms' => 200, 'image_count' => 2, 'trackable_type' => 'App\\Models\\User', 'trackable_id' => '2'],
        // One successful claude request without a trackable
        ['provider' => 'anthropic', 'model' => 'claude-x', 'model_type' => 'text', 'status_code' => 200, 'total_cost_in_cents' => 40, 'latency_ms' => 400, 'prompt_tokens' => 20, 'completion_tokens' => 5],
    ];

    foreach ($rows as $row) {
        SpectraRequest::factory()->create($row + ['created_at' => now()]);
    }
}

afterEach(fn () => Carbon::setTestNow());

it('reports the stats endpoint numbers', function () {
    seedDashboardDataset();

    $response = app()->call([app(StatsController::class), '__invoke'], ['request' => new Request(['period' => 'month'])]);
    $json = $response->toArray();

    // Counts include failures; cost and latency only successes.
    expect($json['total_requests'])->toBe(5)
        ->and($json['total_tokens'])->toBe(205)
        ->and((float) $json['total_cost_in_cents'])->toBe(390.0)
        ->and((float) $json['avg_latency'])->toBe(250.0)
        ->and($json['total_images'])->toBe(2);

    $providers = collect($json['requests_by_provider'])->keyBy('provider');
    expect((int) $providers['openai']['count'])->toBe(3)
        ->and((int) $providers['anthropic']['count'])->toBe(2);

    expect(collect($json['requests_by_date'])->sum('count'))->toEqual(5);

    $topModels = collect($json['top_models'])->keyBy('model');
    expect((int) $topModels['gpt-4o']['count'])->toBe(2)
        ->and((float) $topModels['gpt-4o']['cost'])->toBe(300.0)
        ->and((int) $topModels['claude-x']['count'])->toBe(2)
        ->and((float) $topModels['claude-x']['cost'])->toBe(40.0);

    $types = collect($json['latency_by_model_type'])->keyBy('model_type');
    expect((int) $types['text']['count'])->toBe(4)
        ->and((float) $types['text']['avg_latency'])->toEqualWithDelta(266.67, 0.01)
        ->and((int) $types['image']['count'])->toBe(1)
        ->and((float) $types['image']['avg_latency'])->toBe(200.0);
});

it('reports the trackable detail numbers', function () {
    seedDashboardDataset();

    $response = app()->call(
        [app(ShowTrackableController::class), '__invoke'],
        ['request' => new Request(['period' => 'month', 'type' => 'App\\Models\\User']), 'id' => '1']
    );
    $json = $response->toArray();

    $trackable = $json['trackable'];

    expect($trackable['total_requests'])->toBe(3)
        ->and((float) $trackable['total_cost'])->toBe(300.0)
        ->and((float) $trackable['avg_latency'])->toBe(200.0)
        ->and($trackable['total_tokens'])->toBe(180);

    $byModel = collect($trackable['usage_by_model'])->keyBy('model');
    expect((int) $byModel['gpt-4o']['requests'])->toBe(2)
        ->and((float) $byModel['gpt-4o']['cost'])->toBe(300.0)
        ->and((int) $byModel['claude-x']['requests'])->toBe(1)
        ->and((float) $byModel['claude-x']['cost'])->toBe(0.0);

    $byProvider = collect($trackable['usage_by_provider'])->keyBy('provider');
    expect((int) $byProvider['openai']['requests'])->toBe(2)
        ->and((float) $byProvider['openai']['cost'])->toBe(300.0)
        ->and((int) $byProvider['anthropic']['requests'])->toBe(1)
        ->and((float) $byProvider['anthropic']['cost'])->toBe(0.0);

    expect(collect($trackable['costs_by_date'])->sum('cost'))->toEqual(300.0);
});
