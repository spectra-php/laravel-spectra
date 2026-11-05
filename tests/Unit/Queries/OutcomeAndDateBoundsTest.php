<?php

declare(strict_types=1);

/**
 * The package-wide outcome rule (failed = any status outside 2xx, none, or an
 * error recorded on a 2xx answer; counts include failures, cost and latency
 * don't) in the model, the public usage API and the alerts, and date-only
 * end bounds covering their whole day.
 */

use Illuminate\Http\Request;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Alerts\AlertEvaluator;
use Spectra\Support\Alerts\AlertRule;
use Spectra\Support\Concerns\FiltersTrackables;
use Spectra\Support\Query\CostQueryBuilder;
use Spectra\Support\Query\UsageQueryBuilder;
use Spectra\Support\RequestTransformer;
use Spectra\Support\Tracking\ProviderErrorException;
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

it('treats a 2xx answer that carries an error as failed in the model, its scopes and the exports', function () {
    $jobFailure = SpectraRequest::factory()->create(['status_code' => 200, 'error_type' => ProviderErrorException::class, 'error_message' => 'Prediction failed']);
    $success = SpectraRequest::factory()->create(['status_code' => 200]);

    expect($jobFailure->isFailed())->toBeTrue()
        ->and($jobFailure->isSuccessful())->toBeFalse()
        ->and(SpectraRequest::query()->failed()->pluck('id')->all())->toBe([$jobFailure->id])
        ->and(SpectraRequest::query()->successful()->pluck('id')->all())->toBe([$success->id])
        ->and(SpectraRequest::isSuccessfulOutcome(200, ProviderErrorException::class))->toBeFalse()
        ->and(SpectraRequest::isSuccessfulOutcome(200, null))->toBeTrue()
        ->and(app(RequestTransformer::class)->transform($jobFailure)['is_failed'])->toBeTrue();
});

it('keeps a 2xx answer that carries an error out of the cost query, the trackable usage and the alert spend', function () {
    $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret']);
    $trackable = ['trackable_type' => $user->getMorphClass(), 'trackable_id' => (string) $user->getKey(), 'created_at' => now()->subMinute()];
    SpectraRequest::factory()->create(['provider' => 'openai', 'status_code' => 200, 'total_cost_in_cents' => 100, 'latency_ms' => 100] + $trackable);
    SpectraRequest::factory()->create(['provider' => 'openai', 'status_code' => 200, 'error_type' => ProviderErrorException::class, 'total_cost_in_cents' => 900, 'latency_ms' => 9000] + $trackable);

    $usage = $user->getAiUsageForPeriod(now()->subDay(), now()->addDay());
    $evaluator = app(AlertEvaluator::class);
    $errorRate = $evaluator->measure(AlertRule::fromConfig(['name' => 'Errors', 'metric' => 'error_rate', 'above' => 0.5, 'min_requests' => 1]), now());
    $spend = $evaluator->measure(AlertRule::fromConfig(['name' => 'Spend', 'metric' => 'cost', 'above' => 1]), now());

    expect((new CostQueryBuilder)->totalCents())->toBe(100.0)
        ->and((new CostQueryBuilder)->breakdown()->requestCount)->toBe(1)
        ->and($usage->requestCount)->toBe(2)
        ->and($usage->successfulCount)->toBe(1)
        ->and($usage->failedCount)->toBe(1)
        ->and($usage->totalCostInCents)->toBe(100.0)
        ->and($usage->avgLatencyMs)->toBe(100.0)
        ->and($errorRate)->toBe(0.5)
        ->and($spend)->toBe(100.0);
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
