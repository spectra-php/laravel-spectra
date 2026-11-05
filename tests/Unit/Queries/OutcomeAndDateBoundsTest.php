<?php

declare(strict_types=1);

/**
 * The package-wide outcome rule (failed = any status outside 2xx, or none;
 * counts include failures, cost and latency don't) in the public usage API,
 * and date-only end bounds covering their whole day.
 */

use Illuminate\Http\Request;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Concerns\FiltersTrackables;
use Spectra\Support\Query\CostQueryBuilder;
use Spectra\Support\Query\UsageQueryBuilder;
use Spectra\Support\RequestTransformer;
use Workbench\App\Models\User;

it('treats a 3xx or missing status as failed in the model', function () {
    $redirect = SpectraRequest::factory()->create(['status_code' => 302]);
    $missing = SpectraRequest::factory()->create(['status_code' => null]);
    SpectraRequest::factory()->create(['status_code' => 200]);

    expect(SpectraRequest::query()->failed()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$redirect->id, $missing->id])->sort()->values()->all())
        ->and($redirect->isFailed())->toBeTrue()
        ->and($missing->isFailed())->toBeTrue();
});

it('exports a 3xx request as failed to integrations', function () {
    $request = SpectraRequest::factory()->make(['status_code' => 304]);

    expect(app(RequestTransformer::class)->transform($request)['is_failed'])->toBeTrue();
});

it('includes 3xx requests in the trackables error filter', function () {
    SpectraRequest::factory()->create(['status_code' => 302, 'trackable_type' => 'App\\Models\\User', 'trackable_id' => '1']);
    SpectraRequest::factory()->create(['status_code' => 200, 'trackable_type' => 'App\\Models\\User', 'trackable_id' => '1']);

    $filter = new class
    {
        use FiltersTrackables {
            applyTrackableFilters as public;
        }
    };
    $query = SpectraRequest::query();

    $filter->applyTrackableFilters($query, new Request(['status' => 'error']));

    expect($query->pluck('status_code')->all())->toBe([302]);
});

it('sums usage cost and averages latency over successful requests only while counting every request', function () {
    SpectraRequest::factory()->create(['provider' => 'openai', 'model' => 'gpt-4o', 'status_code' => 200, 'total_cost_in_cents' => 100, 'latency_ms' => 100]);
    SpectraRequest::factory()->create(['provider' => 'openai', 'model' => 'gpt-4o', 'status_code' => 500, 'total_cost_in_cents' => 900, 'latency_ms' => 9000]);

    $stats = (new UsageQueryBuilder)->stats();
    $byModel = (new UsageQueryBuilder)->byModel()->first();

    expect($stats->requestCount)->toBe(2)
        ->and($stats->totalCostInCents)->toBe(100.0)
        ->and($stats->avgLatencyMs)->toBe(100.0)
        ->and($stats->maxLatencyMs)->toBe(100)
        ->and((new UsageQueryBuilder)->totalCostCents())->toBe(100.0)
        ->and((new UsageQueryBuilder)->averageLatency())->toBe(100.0)
        ->and($byModel['request_count'])->toBe(2)
        ->and($byModel['total_cost_in_cents'])->toBe(100.0)
        ->and($byModel['avg_latency_ms'])->toBe(100.0);
});

it('sums a trackable model usage cost over successful requests only while counting every request', function () {
    $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret']);
    SpectraRequest::factory()->create(['provider' => 'openai', 'status_code' => 200, 'total_cost_in_cents' => 100, 'latency_ms' => 100, 'trackable_type' => $user->getMorphClass(), 'trackable_id' => (string) $user->getKey()]);
    SpectraRequest::factory()->create(['provider' => 'openai', 'status_code' => 500, 'total_cost_in_cents' => 900, 'latency_ms' => 9000, 'trackable_type' => $user->getMorphClass(), 'trackable_id' => (string) $user->getKey()]);

    $usage = $user->getAiUsageForPeriod(now()->subDay(), now()->addDay());

    expect($usage->requestCount)->toBe(2)
        ->and($usage->totalCostInCents)->toBe(100.0)
        ->and($usage->avgLatencyMs)->toBe(100.0)
        ->and($user->getAiUsageByProvider(now()->subDay(), now()->addDay())['openai']['total_cost_in_cents'])->toBe(100.0);
});

it('includes the whole end day when a usage period ends on a date-only string', function () {
    $this->travelTo('2026-03-10 12:00:00');
    $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret']);
    SpectraRequest::factory()->create(['status_code' => 200, 'total_cost_in_cents' => 50, 'created_at' => '2026-03-05 18:30:00', 'trackable_type' => $user->getMorphClass(), 'trackable_id' => (string) $user->getKey()]);

    expect($user->getAiUsageForPeriod('2026-03-01', '2026-03-05')->requestCount)->toBe(1)
        ->and((new UsageQueryBuilder)->between('2026-03-01', '2026-03-05')->count())->toBe(1)
        ->and((new CostQueryBuilder)->between('2026-03-01', '2026-03-05')->totalCents())->toBe(50.0);
});

it('includes the end day when daily stats are read with a date-only end', function () {
    SpectraDailyStat::query()->create(['date' => '2026-03-05', 'provider' => 'openai', 'model' => 'gpt-4o', 'request_count' => 3]);

    $stats = SpectraDailyStat::getAggregatedStats('2026-03-01', '2026-03-05');

    expect($stats['total_requests'])->toBe(3);
});
