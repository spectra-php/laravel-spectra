<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Spectra\Actions\Stats\GetModelTypeBreakdown;
use Spectra\Actions\Stats\GetOverviewStats;
use Spectra\Actions\Trackables\GetTrackableLatencyByModelType;
use Spectra\Actions\Trackables\GetTrackablesSummary;
use Spectra\Actions\Trackables\GetTrackableStats;
use Spectra\Actions\Trackables\GetTrackableUsageByProvider;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;
use Spectra\Support\Query\UsageQueryBuilder;
use Spectra\Support\Tracking\ProviderErrorException;

/**
 * Dashboard aggregates count failed requests (a status outside 2xx, none, or
 * an error recorded on a 2xx answer) but leave them out of cost and latency,
 * and keep fractional-cent costs.
 */
function seedMixedOutcomeRequests(): void
{
    // Two successful requests: 100 + 200 cents, 100ms + 300ms latency
    SpectraRequest::factory()->create([
        'model_type' => 'text',
        'status_code' => 200,
        'total_cost_in_cents' => 100,
        'latency_ms' => 100,
        'trackable_type' => 'App\\Models\\User',
        'trackable_id' => '1',
    ]);
    SpectraRequest::factory()->create([
        'model_type' => 'text',
        'status_code' => 201,
        'total_cost_in_cents' => 200,
        'latency_ms' => 300,
        'trackable_type' => 'App\\Models\\User',
        'trackable_id' => '1',
    ]);

    // One failed request with (stale/phantom) cost and huge latency
    SpectraRequest::factory()->create([
        'model_type' => 'text',
        'status_code' => 500,
        'total_cost_in_cents' => 999,
        'latency_ms' => 30000,
        'trackable_type' => 'App\\Models\\User',
        'trackable_id' => '1',
    ]);

    // One job the provider reported as failed inside an HTTP 200
    SpectraRequest::factory()->create([
        'model_type' => 'text',
        'status_code' => 200,
        'error_type' => ProviderErrorException::class,
        'total_cost_in_cents' => 555,
        'latency_ms' => 20000,
        'trackable_type' => 'App\\Models\\User',
        'trackable_id' => '1',
    ]);
}

it('excludes failed requests from cost and latency in the overview stats but counts them', function () {
    seedMixedOutcomeRequests();

    $stats = app(GetOverviewStats::class)(DateRange::fromRequest(new Request), 'full');

    expect($stats->total_requests)->toBe(4)
        ->and($stats->total_cost_in_cents)->toBe(300.0)
        ->and($stats->avg_latency)->toBe(200.0);
});

it('excludes failed requests from cost and latency in the model type breakdown', function () {
    seedMixedOutcomeRequests();

    $rows = app(GetModelTypeBreakdown::class)(DateRange::fromRequest(new Request), 'full');
    $text = $rows->firstWhere('model_type', 'text');

    expect($text->count)->toBe(4)
        ->and((float) $text->cost)->toBe(300.0)
        ->and((float) $text->avg_latency)->toBe(200.0);
});

it('excludes failed requests from trackable stats cost and latency', function () {
    seedMixedOutcomeRequests();

    $stats = app(GetTrackableStats::class)('App\\Models\\User', '1', DateRange::fromRequest(new Request));

    expect($stats->total_requests)->toBe(4)
        ->and($stats->total_cost)->toBe(300.0)
        ->and($stats->avg_latency)->toBe(200.0);
});

it('excludes failed requests from trackable latency-by-model-type cost', function () {
    seedMixedOutcomeRequests();

    $rows = app(GetTrackableLatencyByModelType::class)('App\\Models\\User', '1', DateRange::fromRequest(new Request));
    $text = $rows->firstWhere('model_type', 'text');

    expect((float) $text->cost)->toBe(300.0)
        ->and((float) $text->avg_latency)->toBe(200.0);
});

it('excludes failed requests from trackable usage-by-provider cost', function () {
    seedMixedOutcomeRequests();

    $rows = app(GetTrackableUsageByProvider::class)('App\\Models\\User', '1', DateRange::fromRequest(new Request));

    expect($rows->first()->cost)->toBe(300.0)
        ->and($rows->first()->requests)->toBe(4);
});

it('excludes failed requests from the trackables summary cost', function () {
    seedMixedOutcomeRequests();

    $summary = app(GetTrackablesSummary::class)(new Request, DateRange::fromRequest(new Request));

    expect($summary->total_requests)->toBe(4)
        ->and($summary->total_cost)->toBe(300.0)
        ->and($summary->avg_latency)->toBe(200.0);
});

it('counts 3xx responses and 2xx answers with an error as failed so success and failure cover every request', function () {
    SpectraRequest::factory()->create(['status_code' => 200]);
    SpectraRequest::factory()->create(['status_code' => 301]);
    SpectraRequest::factory()->create(['status_code' => 500]);
    SpectraRequest::factory()->create(['status_code' => null]);
    SpectraRequest::factory()->create(['status_code' => 200, 'error_type' => ProviderErrorException::class]);

    $stats = (new UsageQueryBuilder)->stats();

    expect($stats->requestCount)->toBe(5)
        ->and($stats->successfulCount)->toBe(1)
        ->and($stats->failedCount)->toBe(4)
        ->and($stats->successfulCount + $stats->failedCount)->toBe($stats->requestCount)
        ->and((new UsageQueryBuilder)->failed()->count())->toBe(4)
        ->and((new UsageQueryBuilder)->successful()->count())->toBe(1);
});

it('does not truncate fractional-cent costs in usage stats', function () {
    SpectraRequest::factory()->create(['status_code' => 200, 'total_cost_in_cents' => 0.4567]);

    $stats = (new UsageQueryBuilder)->stats();

    expect($stats->totalCostInCents)->toEqualWithDelta(0.4567, 0.0001);
});

it('groups costs into one row per calendar month on SQLite', function () {
    SpectraRequest::factory()->create([
        'status_code' => 200,
        'total_cost_in_cents' => 150,
        'created_at' => now()->subMonthNoOverflow(),
    ]);
    SpectraRequest::factory()->create([
        'status_code' => 200,
        'total_cost_in_cents' => 250,
        'created_at' => now(),
    ]);

    $rows = Spectra::costs()->monthly();

    expect($rows)->toHaveCount(2)
        ->and($rows->last()['total_cost_in_cents'])->toBe(250.0)
        ->and($rows->last()['year'])->toBe(now()->year)
        ->and($rows->last()['month'])->toBe(now()->month)
        ->and($rows->last()['period'])->toBe(now()->format('Y-m'));
});
