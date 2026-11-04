<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Spectra\Http\Controllers\Api\PerformanceController;
use Spectra\Models\SpectraRequest;

/**
 * The overview's Performance card: per model, the error rate over every call
 * and latency percentiles (nearest rank) over the successful ones.
 */
it('reports each model\'s error rate and latency percentiles over its successful calls', function () {
    foreach (range(1, 20) as $i) {
        SpectraRequest::factory()->create(['provider' => 'openai', 'model' => 'gpt-4o', 'status_code' => 200, 'latency_ms' => $i * 100, 'is_streaming' => true, 'time_to_first_token_ms' => $i * 10]);
    }
    SpectraRequest::factory()->count(5)->create(['provider' => 'openai', 'model' => 'gpt-4o', 'status_code' => 503, 'latency_ms' => 99_000]);
    SpectraRequest::factory()->create(['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'status_code' => 200, 'latency_ms' => 700]);

    $models = collect(app()->call([app(PerformanceController::class), '__invoke'], ['request' => new Request(['period' => 'all'])])->toArray()['models'])
        ->map(fn ($row) => is_array($row) ? $row : $row->toArray())
        ->keyBy('model');

    expect($models['gpt-4o'])->toMatchArray([
        'request_count' => 25,
        'failed_count' => 5,
        'error_rate' => 0.2,
        // 20 successful calls, 100..2000 ms: p50 is the 10th, p95 the 19th, p99 the 20th.
        'latency_p50' => 1000,
        'latency_p95' => 1900,
        'latency_p99' => 2000,
        'ttft_p50' => 100,
    ])->and($models['claude-sonnet-4-6'])->toMatchArray(['error_rate' => 0.0, 'latency_p95' => 700, 'ttft_p50' => null]);
});
