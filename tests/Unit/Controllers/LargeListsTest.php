<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spectra\Actions\Stats\GetModelPerformance;
use Spectra\Actions\Trackables\GetAvailableTrackableTypes;
use Spectra\Http\Controllers\Api\RequestsController;
use Spectra\Http\Controllers\Api\TagsController;
use Spectra\Http\Controllers\Api\TracesController;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestCounter;
use Spectra\Support\StatsAggregator;

/**
 * The list screens on a table too large to walk: pages stay stable and cheap,
 * totals come from the daily stats or stop at a limit, and whatever became an
 * estimate says so in the answer.
 */
function listRequests(array $query): array
{
    return app()->call([app(RequestsController::class), '__invoke'], ['request' => new Request(['period' => 'all', ...$query])])->toArray();
}

it('keeps calls of the same second on one page each across the pages', function () {
    SpectraRequest::factory()->count(30)->create(['created_at' => now()->startOfSecond()]);

    $first = listRequests(['per_page' => 10, 'page' => 1]);
    $second = listRequests(['per_page' => 10, 'page' => 2]);
    $third = listRequests(['per_page' => 10, 'page' => 3]);

    $ids = collect([...$first['data'], ...$second['data'], ...$third['data']])->pluck('id');

    expect($ids->unique())->toHaveCount(30);
});

it('walks the same rows by cursor as by page', function () {
    foreach (range(1, 12) as $minute) {
        SpectraRequest::factory()->create(['created_at' => now()->subMinutes($minute)]);
    }

    $first = listRequests(['per_page' => 5]);
    $second = listRequests(['per_page' => 5, 'cursor' => $first['next_cursor']]);
    $byPage = listRequests(['per_page' => 5, 'page' => 2]);

    expect(collect($second['data'])->pluck('id')->all())->toBe(collect($byPage['data'])->pluck('id')->all())
        ->and($second['next_cursor'])->not->toBeNull();
});

it('ignores a cursor it cannot read', function () {
    SpectraRequest::factory()->count(3)->create();

    expect(listRequests(['cursor' => 'not-a-cursor'])['data'])->toHaveCount(3);
});

it('reads a page without selecting the bodies or counting its rows twice', function () {
    SpectraRequest::factory()->count(3)->create(['request' => ['secret' => 'body']]);
    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = $query->sql;
    });

    $page = listRequests([]);

    expect($page['data'][0])->not->toHaveKey('request')
        ->and(collect($statements)->filter(fn (string $sql) => str_contains($sql, '"request"') || str_contains($sql, '`request`')))->toBeEmpty();
});

it('stops counting a filter the daily stats do not hold, and says so', function () {
    $now = now()->format('Y-m-d H:i:s');
    $row = fn () => ['id' => (string) Str::uuid7(), 'provider' => 'openai', 'model' => 'gpt-4o', 'finish_reason' => 'stop', 'created_at' => $now];

    foreach (array_chunk(range(0, RequestCounter::LIMIT), 1000) as $chunk) {
        DB::table('spectra_requests')->insert(array_map(fn () => $row(), $chunk));
    }

    $page = listRequests(['finish_reason' => 'stop', 'page' => 2, 'per_page' => 10]);

    expect($page['total_is_estimate'])->toBeTrue()
        ->and($page['total'])->toBe(RequestCounter::LIMIT)
        ->and($page['data'])->toHaveCount(10);
});

it('counts exactly below that limit', function () {
    SpectraRequest::factory()->count(3)->create(['finish_reason' => 'stop']);

    $page = listRequests(['finish_reason' => 'stop']);

    expect($page['total_is_estimate'])->toBeFalse()
        ->and($page['total'])->toBe(3);
});

it('sorts by cost within the latest calls past the exact row limit, and says so', function () {
    config(['spectra.dashboard.exact_row_limit' => 2]);

    foreach ([100, 1, 2, 3] as $minute => $cost) {
        SpectraRequest::factory()->create(['total_cost_in_cents' => $cost, 'created_at' => now()->subMinutes(10 - $minute)]);
    }
    config(['spectra.dashboard.read_from_rollups' => true]);
    app(StatsAggregator::class)->rebuild();

    $page = listRequests(['sort_by' => 'total_cost_in_cents']);

    expect($page['sorted_within_latest'])->toBe(2)
        ->and(collect($page['data'])->pluck('total_cost_in_cents')->all())->toEqual([3.0, 2.0]);
});

it('sorts every call below that limit', function () {
    foreach ([100, 1, 2] as $cost) {
        SpectraRequest::factory()->create(['total_cost_in_cents' => $cost]);
    }

    $page = listRequests(['sort_by' => 'total_cost_in_cents']);

    expect($page['sorted_within_latest'])->toBeNull()
        ->and($page['data'][0]['total_cost_in_cents'])->toEqual(100.0);
});

it('lists the latest traces and marks the totals as estimates past the exact row limit', function () {
    config(['spectra.dashboard.read_from_rollups' => true, 'spectra.dashboard.exact_row_limit' => 2]);

    foreach (range(1, 4) as $trace) {
        foreach (range(1, 2) as $call) {
            SpectraRequest::factory()->create(['trace_id' => "trace-{$trace}", 'created_at' => now()->subMinutes(10 - $trace), 'started_at' => now()->subMinutes(10 - $trace), 'status_code' => 200, 'error_type' => null, 'total_cost_in_cents' => 5]);
        }
    }
    app(StatsAggregator::class)->rebuild();

    $traces = app()->call([app(TracesController::class), '__invoke'], ['request' => new Request(['period' => 'all', 'per_page' => 2])])->toArray();

    expect(collect($traces['data'])->pluck('trace_id')->all())->toBe(['trace-4', 'trace-3'])
        ->and($traces['data'][0]['request_count'])->toBe(2)
        ->and($traces['total_is_estimate'])->toBeTrue()
        ->and($traces['summary']['estimated'])->toBeTrue()
        ->and($traces['summary']['trace_count'])->toBe(4)
        ->and($traces['summary']['total_cost_in_cents'])->toEqualWithDelta(40.0, 0.001);
});

it('keeps the exact traces below the limit', function () {
    SpectraRequest::factory()->count(2)->create(['trace_id' => 'one-trace']);

    $traces = app()->call([app(TracesController::class), '__invoke'], ['request' => new Request(['period' => 'all'])])->toArray();

    expect($traces['total_is_estimate'])->toBeFalse()
        ->and($traces['summary']['estimated'])->toBeFalse()
        ->and($traces['total'])->toBe(1);
});

it('marks percentiles that cover only the latest calls', function () {
    SpectraRequest::factory()->count(3)->create(['provider' => 'openai', 'model' => 'gpt-4o', 'status_code' => 200, 'error_type' => null, 'latency_ms' => 100]);

    $performance = app(GetModelPerformance::class)(new DateRange(null, null), 'full');

    expect($performance->first()->sampled)->toBeFalse()
        ->and($performance->first()->latency_p95)->toBe(100);
});

it('finds every trackable type with one lookup per type', function () {
    SpectraRequest::factory()->create(['trackable_type' => 'App\\User', 'trackable_id' => '1']);
    SpectraRequest::factory()->create(['trackable_type' => 'App\\User', 'trackable_id' => '2']);
    SpectraRequest::factory()->create(['trackable_type' => 'App\\Team', 'trackable_id' => '1']);
    SpectraRequest::factory()->create(['trackable_type' => null, 'trackable_id' => null]);

    expect(app(GetAvailableTrackableTypes::class)())->toBe(['App\\Team', 'App\\User']);
});

it('counts a tag\'s requests from the links alone', function () {
    $request = SpectraRequest::factory()->create();
    $request->attachTags(['one', 'two', 'one']);
    SpectraRequest::factory()->create()->attachTags(['one']);

    $tags = app()->call([app(TagsController::class), '__invoke'])->toArray()['tags'];

    expect(collect($tags)->pluck('count', 'name')->all())->toEqual(['one' => 2, 'two' => 1]);
});
