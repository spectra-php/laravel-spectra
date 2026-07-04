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
}

it('excludes failed requests from cost and latency in the overview stats but counts them', function () {
    seedMixedOutcomeRequests();

    $stats = app(GetOverviewStats::class)(DateRange::fromRequest(new Request), 'full');

    expect($stats->total_requests)->toBe(3)
        ->and($stats->total_cost_in_cents)->toBe(300.0)
        ->and($stats->avg_latency)->toBe(200.0);
});

it('excludes failed requests from cost and latency in the model type breakdown', function () {
    seedMixedOutcomeRequests();

    $rows = app(GetModelTypeBreakdown::class)(DateRange::fromRequest(new Request), 'full');
    $text = $rows->firstWhere('model_type', 'text');

    expect($text->count)->toBe(3)
        ->and((float) $text->cost)->toBe(300.0)
        ->and((float) $text->avg_latency)->toBe(200.0);
});

it('excludes failed requests from trackable stats cost and latency', function () {
    seedMixedOutcomeRequests();

    $stats = app(GetTrackableStats::class)('App\\Models\\User', '1', DateRange::fromRequest(new Request));

    expect($stats->total_requests)->toBe(3)
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
        ->and($rows->first()->requests)->toBe(3);
});

it('excludes failed requests from the trackables summary cost', function () {
    seedMixedOutcomeRequests();

    $summary = app(GetTrackablesSummary::class)(new Request, DateRange::fromRequest(new Request));

    expect($summary->total_requests)->toBe(3)
        ->and($summary->total_cost)->toBe(300.0)
        ->and($summary->avg_latency)->toBe(200.0);
});

it('counts 3xx responses as failed so success and failure cover every request', function () {
    SpectraRequest::factory()->create(['status_code' => 200]);
    SpectraRequest::factory()->create(['status_code' => 301]);
    SpectraRequest::factory()->create(['status_code' => 500]);
    SpectraRequest::factory()->create(['status_code' => null]);

    $stats = (new UsageQueryBuilder)->stats();

    expect($stats->requestCount)->toBe(4)
        ->and($stats->successfulCount)->toBe(1)
        ->and($stats->failedCount)->toBe(3)
        ->and($stats->successfulCount + $stats->failedCount)->toBe($stats->requestCount);
});

it('does not truncate fractional-cent costs in usage stats', function () {
    SpectraRequest::factory()->create(['status_code' => 200, 'total_cost_in_cents' => 0.4567]);

    $stats = (new UsageQueryBuilder)->stats();

    expect($stats->totalCostInCents)->toEqualWithDelta(0.4567, 0.0001);
});

it('groups monthly costs without EXTRACT so SQLite works', function () {
    SpectraRequest::factory()->create([
        'status_code' => 200,
        'total_cost_in_cents' => 150,
        'created_at' => now()->subMonth(),
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
