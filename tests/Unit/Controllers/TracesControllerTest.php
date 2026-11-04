<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Spectra\Http\Controllers\Api\ShowRequestController;
use Spectra\Http\Controllers\Api\ShowTraceController;
use Spectra\Http\Controllers\Api\TracesController;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraTag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Workbench\App\Models\User;

/**
 * The traces list and trace endpoints: one row of totals per trace, filters
 * that keep whole traces, search, sort, the summary, and the trace's calls
 * on its own timeline.
 */
const CHECKOUT_TRACE = '0b6f3a52-6d47-4a5e-9a51-2a7c1f0e9d11';
const SEARCH_TRACE = '6a1f0c2e-3b4d-4e5f-8a9b-0c1d2e3f4a5b';

function callTraces(array $query = []): array
{
    return app()->call([app(TracesController::class), '__invoke'], ['request' => new Request(['period' => 'all', ...$query])])->toArray();
}

function callTrace(string $traceId): array
{
    return app()->call([app(ShowTraceController::class), '__invoke'], ['traceId' => $traceId])->toArray();
}

/**
 * A call of the checkout trace, started at the given offset in milliseconds.
 *
 * @param  array<string, mixed>  $attributes
 */
function checkoutCall(int $offsetMs, array $attributes = []): SpectraRequest
{
    $start = Date::parse('2026-11-03 10:00:00')->addMilliseconds($offsetMs);

    return SpectraRequest::factory()->create([
        'trace_id' => CHECKOUT_TRACE,
        'started_at' => $start,
        'created_at' => $start->copy()->startOfSecond(),
        ...$attributes,
    ]);
}

it('lists one row of totals per trace', function () {
    checkoutCall(0, ['origin' => 'POST /checkout', 'model' => 'text-embedding-3-small', 'latency_ms' => 120, 'total_cost_in_cents' => 1, 'prompt_tokens' => 50]);
    checkoutCall(150, ['origin' => 'POST /checkout', 'model' => 'gpt-4o', 'latency_ms' => 900, 'total_cost_in_cents' => 40, 'prompt_tokens' => 400, 'completion_tokens' => 100]);
    checkoutCall(1100, ['origin' => 'App\Jobs\SendReceipt', 'model' => 'gpt-4o', 'status_code' => 500, 'latency_ms' => 300, 'total_cost_in_cents' => 900]);
    SpectraRequest::factory()->create(['trace_id' => null]);

    $json = callTraces();
    $trace = $json['data'][0];

    expect($json['total'])->toBe(1)
        ->and($trace['trace_id'])->toBe(CHECKOUT_TRACE)
        ->and($trace['origin'])->toBe('POST /checkout')
        ->and($trace['request_count'])->toBe(3)
        ->and($trace['failed_count'])->toBe(1)
        // The failed call's 900 cents are not spend.
        ->and($trace['total_cost_in_cents'])->toEqualWithDelta(41.0, 0.0001)
        ->and($trace['total_tokens'])->toBe(550)
        // The last call starts at +1100 ms and runs 300 ms.
        ->and($trace['duration_ms'])->toBe(1400)
        ->and($trace['started_at'])->toStartWith('2026-11-03T10:00:00.000')
        ->and(collect($trace['models'])->pluck('model')->all())->toBe(['text-embedding-3-small', 'gpt-4o']);
});

it('starts calls saved before started_at existed at created_at', function () {
    SpectraRequest::factory()->create(['trace_id' => CHECKOUT_TRACE, 'started_at' => null, 'created_at' => Date::parse('2026-11-03 10:00:00'), 'latency_ms' => 500]);
    SpectraRequest::factory()->create(['trace_id' => CHECKOUT_TRACE, 'started_at' => null, 'created_at' => Date::parse('2026-11-03 10:00:02'), 'latency_ms' => 500]);

    expect(callTraces()['data'][0]['duration_ms'])->toBe(2500);
});

it('keeps every call of a trace in its totals when a filter matches one call', function () {
    checkoutCall(0, ['model' => 'text-embedding-3-small', 'total_cost_in_cents' => 1]);
    checkoutCall(100, ['model' => 'gpt-4o', 'total_cost_in_cents' => 40]);
    SpectraRequest::factory()->create(['trace_id' => SEARCH_TRACE, 'model' => 'claude-x', 'total_cost_in_cents' => 7]);

    $json = callTraces(['model' => 'gpt-4o']);

    expect($json['total'])->toBe(1)
        ->and($json['data'][0]['request_count'])->toBe(2)
        ->and($json['data'][0]['total_cost_in_cents'])->toEqualWithDelta(41.0, 0.0001);
});

it('keeps only traces with a failed call when filtering by errors', function () {
    checkoutCall(0, ['status_code' => 200]);
    checkoutCall(100, ['status_code' => 429]);
    SpectraRequest::factory()->create(['trace_id' => SEARCH_TRACE, 'status_code' => 200]);

    expect(collect(callTraces(['status' => 'error'])['data'])->pluck('trace_id')->all())->toBe([CHECKOUT_TRACE]);
});

it('finds a trace by its exact id or by the origin of a call', function () {
    checkoutCall(0, ['origin' => 'POST /checkout']);
    SpectraRequest::factory()->create(['trace_id' => SEARCH_TRACE, 'origin' => 'artisan reports:send']);

    expect(collect(callTraces(['search' => SEARCH_TRACE])['data'])->pluck('trace_id')->all())->toBe([SEARCH_TRACE])
        ->and(collect(callTraces(['search' => 'checkout'])['data'])->pluck('trace_id')->all())->toBe([CHECKOUT_TRACE]);
});

it('sorts traces by cost and by duration', function () {
    checkoutCall(0, ['latency_ms' => 100, 'total_cost_in_cents' => 50]);
    SpectraRequest::factory()->create(['trace_id' => SEARCH_TRACE, 'latency_ms' => 5000, 'total_cost_in_cents' => 2]);

    expect(collect(callTraces(['sort_by' => 'cost'])['data'])->pluck('trace_id')->all())->toBe([CHECKOUT_TRACE, SEARCH_TRACE])
        ->and(collect(callTraces(['sort_by' => 'duration'])['data'])->pluck('trace_id')->all())->toBe([SEARCH_TRACE, CHECKOUT_TRACE]);
});

it('sums the summary over every matching trace, not just the page', function () {
    checkoutCall(0, ['total_cost_in_cents' => 30]);
    checkoutCall(100, ['status_code' => 500]);
    SpectraRequest::factory()->create(['trace_id' => SEARCH_TRACE, 'total_cost_in_cents' => 10]);

    $summary = callTraces(['per_page' => 1])['summary'];

    expect($summary['trace_count'])->toBe(2)
        ->and($summary['failed_trace_count'])->toBe(1)
        ->and($summary['total_cost_in_cents'])->toEqualWithDelta(40.0, 0.0001)
        ->and($summary['avg_cost_in_cents'])->toEqualWithDelta(20.0, 0.0001)
        ->and($summary['avg_request_count'])->toBe(1.5);
});

it('names the trackable of a trace only when all its calls share one', function () {
    $ada = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret']);
    $grace = User::query()->create(['name' => 'Grace', 'email' => 'grace@example.com', 'password' => 'secret']);

    checkoutCall(0, ['trackable_type' => $ada->getMorphClass(), 'trackable_id' => (string) $ada->getKey()]);
    checkoutCall(100, ['trackable_type' => $ada->getMorphClass(), 'trackable_id' => (string) $ada->getKey()]);
    SpectraRequest::factory()->create(['trace_id' => SEARCH_TRACE, 'trackable_type' => $ada->getMorphClass(), 'trackable_id' => (string) $ada->getKey()]);
    SpectraRequest::factory()->create(['trace_id' => SEARCH_TRACE, 'trackable_type' => $grace->getMorphClass(), 'trackable_id' => (string) $grace->getKey()]);

    $traces = collect(callTraces()['data'])->keyBy('trace_id');

    expect($traces[CHECKOUT_TRACE]['trackable']['name'])->toBe('Ada')
        ->and($traces[SEARCH_TRACE]['trackable'])->toBeNull();
});

it('shows a trace with its calls on one millisecond timeline', function () {
    $tag = SpectraTag::findOrCreateByName('checkout');
    $first = checkoutCall(0, ['origin' => 'POST /checkout', 'model' => 'gpt-4o', 'latency_ms' => 120, 'total_cost_in_cents' => 4]);
    $first->tags()->attach($tag);
    checkoutCall(250, ['origin' => 'App\Jobs\SendReceipt', 'model' => 'gpt-4o', 'latency_ms' => 400, 'total_cost_in_cents' => 6]);
    checkoutCall(320, ['model' => 'claude-x', 'status_code' => 500, 'latency_ms' => 50, 'total_cost_in_cents' => 99]);

    $trace = callTrace(CHECKOUT_TRACE);

    expect($trace['request_count'])->toBe(3)
        ->and($trace['failed_count'])->toBe(1)
        ->and($trace['origin'])->toBe('POST /checkout')
        ->and($trace['duration_ms'])->toBe(650)
        ->and($trace['position'])->toBeNull()
        ->and(collect($trace['requests'])->pluck('offset_ms')->all())->toBe([0, 250, 320])
        ->and(collect($trace['requests'])->pluck('origin')->all())->toBe(['POST /checkout', 'App\Jobs\SendReceipt', null])
        ->and(collect($trace['requests'])->pluck('is_error')->all())->toBe([false, false, true])
        ->and($trace['models'][0])->toMatchArray(['model' => 'gpt-4o', 'request_count' => 2, 'total_cost_in_cents' => 10.0])
        ->and($trace['models'][1])->toMatchArray(['model' => 'claude-x', 'request_count' => 1, 'total_cost_in_cents' => 0.0])
        ->and($trace['tags'])->toBe(['checkout']);
});

it('marks the current call and its place in the trace on the request screen', function () {
    checkoutCall(0);
    $second = checkoutCall(400);

    $trace = app()->call([app(ShowRequestController::class), '__invoke'], ['id' => $second->id])->toArray()['trace'];

    expect($trace['position'])->toBe(2)
        ->and(collect($trace['requests'])->pluck('is_current')->all())->toBe([false, true]);
});

it('answers 404 for a trace with no calls', function () {
    callTrace('9f8e7d6c-5b4a-4f3e-8d2c-1b0a9f8e7d6c');
})->throws(NotFoundHttpException::class);
