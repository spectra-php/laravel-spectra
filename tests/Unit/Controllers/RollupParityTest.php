<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Spectra\Http\Controllers\Api\CostsController;
use Spectra\Http\Controllers\Api\ErrorsController;
use Spectra\Http\Controllers\Api\PerformanceController;
use Spectra\Http\Controllers\Api\RequestsController;
use Spectra\Http\Controllers\Api\ShowTrackableController;
use Spectra\Http\Controllers\Api\StatsController;
use Spectra\Http\Controllers\Api\TrackablesController;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RollupSchema;
use Spectra\Support\StatsAggregator;
use Workbench\App\Models\User;

/**
 * The dashboard's numbers are the same from the daily stats as from the
 * requests: counts include failures, cost and latency cover successful calls
 * only. The screens read the daily stats on large tables, so any difference
 * here is a wrong number on a real dashboard.
 */
function seedParityRequests(): void
{
    $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'x']);
    $type = $user->getMorphClass();
    $id = (string) $user->getKey();

    $rows = [
        ['provider' => 'openai', 'model' => 'gpt-4o', 'model_type' => 'text', 'status_code' => 200, 'total_cost_in_cents' => 100.25, 'latency_ms' => 100, 'prompt_tokens' => 100, 'completion_tokens' => 50, 'trackable_type' => $type, 'trackable_id' => $id],
        ['provider' => 'openai', 'model' => 'gpt-4o', 'model_type' => 'text', 'status_code' => 201, 'total_cost_in_cents' => 200, 'latency_ms' => 300, 'prompt_tokens' => 10, 'completion_tokens' => 10, 'trackable_type' => $type, 'trackable_id' => $id],
        // A failure: counts, but its cost and latency don't.
        ['provider' => 'anthropic', 'model' => 'claude-x', 'model_type' => 'text', 'status_code' => 500, 'error_type' => 'server_error', 'total_cost_in_cents' => 999, 'latency_ms' => 30000, 'prompt_tokens' => 5, 'completion_tokens' => 5, 'trackable_type' => $type, 'trackable_id' => $id, 'error_fingerprint' => 'aaaaaaaaaaaaaaaa'],
        // A broken stream: a 200 with an error.
        ['provider' => 'openai', 'model' => 'gpt-4o', 'model_type' => 'text', 'status_code' => 200, 'error_type' => 'stream_error', 'total_cost_in_cents' => 77, 'latency_ms' => 900, 'prompt_tokens' => 1, 'completion_tokens' => 1, 'error_fingerprint' => 'bbbbbbbbbbbbbbbb'],
        // No status at all, without a fingerprint.
        ['provider' => 'groq', 'model' => 'whisper', 'model_type' => 'stt', 'status_code' => null, 'total_cost_in_cents' => 0, 'latency_ms' => null],
        // A successful call without a latency: the average must skip it.
        ['provider' => 'openai', 'model' => 'gpt-image-1', 'model_type' => 'image', 'status_code' => 200, 'total_cost_in_cents' => 50, 'latency_ms' => null, 'image_count' => 2, 'trackable_type' => $type, 'trackable_id' => $id],
        ['provider' => 'openai', 'model' => 'tts-1', 'model_type' => 'tts', 'status_code' => 200, 'total_cost_in_cents' => 3, 'latency_ms' => 50, 'input_characters' => 400, 'duration_seconds' => 12.5],
        ['provider' => 'openai', 'model' => 'whisper-1', 'model_type' => 'stt', 'status_code' => 200, 'total_cost_in_cents' => 2, 'latency_ms' => 70, 'duration_seconds' => 60],
        // A model nothing prices.
        ['provider' => 'openai', 'model' => 'not-in-the-catalog', 'model_type' => 'text', 'status_code' => 200, 'total_cost_in_cents' => 0, 'latency_ms' => 10],
        // Yesterday and last week.
        ['provider' => 'anthropic', 'model' => 'claude-x', 'model_type' => 'text', 'status_code' => 200, 'total_cost_in_cents' => 40, 'latency_ms' => 400, 'prompt_tokens' => 20, 'completion_tokens' => 5, 'created_at' => now()->subDay()],
        ['provider' => 'openai', 'model' => 'gpt-4o', 'model_type' => 'text', 'status_code' => 200, 'total_cost_in_cents' => 11, 'latency_ms' => 110, 'trackable_type' => $type, 'trackable_id' => $id, 'created_at' => now()->subDays(8)],
    ];

    foreach ($rows as $row) {
        SpectraRequest::factory()->create($row + ['created_at' => now(), 'error_type' => null, 'prompt_tokens' => 0, 'completion_tokens' => 0]);
    }

    app(StatsAggregator::class)->rebuild();
}

/** Call a controller with the daily stats on or off, as plain data. */
function dashboardAnswer(string $controller, array $query, bool $onRollups, array $arguments = []): array
{
    config(['spectra.dashboard.read_from_rollups' => $onRollups]);

    $response = app()->call([app($controller), '__invoke'], ['request' => new Request($query), ...$arguments]);

    return json_decode(json_encode($response->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
}

/** Round every float, as the sums come back through different arithmetic. */
function comparable(array $answer): array
{
    return Arr::map($answer, fn (mixed $value) => match (true) {
        is_array($value) => comparable($value),
        is_float($value) => round($value, 3),
        default => $value,
    });
}

beforeEach(function () {
    $this->travelTo('2026-07-15 12:00:00');
    seedParityRequests();
});

dataset('periods', [
    'today' => [['period' => 'today']],
    'this month' => [['period' => 'month']],
    'custom days' => [['start_date' => '2026-07-01', 'end_date' => '2026-07-15']],
    'all time' => [['period' => 'all']],
]);

it('answers the same on the overview', function (array $query) {
    expect(comparable(dashboardAnswer(StatsController::class, $query, true)))
        ->toEqual(comparable(dashboardAnswer(StatsController::class, $query, false)));
})->with('periods');

it('answers the same on the costs screen', function (array $query) {
    expect(comparable(dashboardAnswer(CostsController::class, $query, true)))
        ->toEqual(comparable(dashboardAnswer(CostsController::class, $query, false)));
})->with('periods');

it('answers the same on the performance card', function (array $query) {
    expect(comparable(dashboardAnswer(PerformanceController::class, $query, true)))
        ->toEqual(comparable(dashboardAnswer(PerformanceController::class, $query, false)));
})->with('periods');

it('answers the same on the trackables screen', function (array $query) {
    expect(comparable(dashboardAnswer(TrackablesController::class, $query, true)))
        ->toEqual(comparable(dashboardAnswer(TrackablesController::class, $query, false)));
})->with('periods');

it('answers the same on the errors screen', function (array $query) {
    expect(comparable(dashboardAnswer(ErrorsController::class, $query, true)))
        ->toEqual(comparable(dashboardAnswer(ErrorsController::class, $query, false)));
})->with('periods');

it('answers the same for one trackable', function (array $query) {
    $user = User::query()->sole();
    $arguments = ['id' => (string) $user->getKey()];
    $query += ['type' => $user->getMorphClass()];

    expect(comparable(dashboardAnswer(ShowTrackableController::class, $query, true, $arguments)))
        ->toEqual(comparable(dashboardAnswer(ShowTrackableController::class, $query, false, $arguments)));
})->with('periods');

it('counts the same requests under a filter the daily stats hold', function (array $filter, int $expected) {
    $query = ['period' => 'all', ...$filter];

    expect(dashboardAnswer(RequestsController::class, $query, true)['total'])->toBe($expected)
        ->and(dashboardAnswer(RequestsController::class, $query, false)['total'])->toBe($expected);
})->with([
    'nothing' => [[], 11],
    'a provider' => [['provider' => 'anthropic'], 2],
    'failed' => [['status' => 'error'], 3],
    'successful' => [['status' => 'success'], 8],
    'a model type' => [['model_type' => 'stt'], 2],
    'a model name' => [['model' => 'gpt'], 5],
]);

it('reads the requests when a period starts or ends mid-day', function () {
    $range = new DateRange(now()->subHours(2), null);

    expect(app(RollupSchema::class)->covers($range))->toBeFalse();
});

it('reads the requests until the key migration has run', function () {
    Schema::table('spectra_daily_stats', function ($table) {
        $table->dropUnique('spectra_daily_stats_stat_key_unique');
        $table->dropColumn(['stat_key', 'latency_samples']);
    });
    app()->forgetInstance(RollupSchema::class);
    config(['spectra.dashboard.read_from_rollups' => true]);

    $stats = app()->call([app(StatsController::class), '__invoke'], ['request' => new Request(['period' => 'all'])])->toArray();

    expect($stats['total_requests'])->toBe(11);
});

it('adds up the same numbers calling the rebuild as saving each call', function () {
    SpectraDailyStat::query()->delete();
    $aggregator = app(StatsAggregator::class);
    SpectraRequest::query()->get()->each(fn (SpectraRequest $request) => $aggregator->recordRequest($request));
    $key = fn (SpectraDailyStat $stat) => implode('|', [$stat->date->format('Y-m-d'), $stat->provider, $stat->model, $stat->model_type, $stat->trackable_type, $stat->trackable_id]);
    $columns = fn (SpectraDailyStat $stat) => Arr::except($stat->attributesToArray(), ['id', 'date', 'created_at', 'updated_at', 'avg_latency_ms']);
    $saved = SpectraDailyStat::query()->get()->keyBy($key)->map($columns)->sortKeys()->all();

    $aggregator->rebuild();

    $rebuilt = SpectraDailyStat::query()->get()->keyBy($key)->map($columns)->sortKeys()->all();

    expect(array_keys($rebuilt))->toBe(array_keys($saved))
        ->and(comparable($rebuilt))->toEqual(comparable($saved));
});
