<?php

declare(strict_types=1);

namespace Spectra\Actions\Dashboards\Sections;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Spectra\Data\Responses\DashboardPageResponse;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Dashboards\DashboardScope;
use Spectra\Support\DateRange;
use stdClass;

/**
 * The trackable's traces for a shared dashboard, newest first: only those whose
 * every call is theirs, and without the origin, which names the app's routes and
 * jobs.
 */
class GetDashboardTraces
{
    /** ISO 8601 with milliseconds, as the screen reads it. */
    private const ISO_MILLISECONDS = 'Y-m-d\\TH:i:s.vP';

    /**
     * Get one page of the trackable's traces in the date range.
     */
    public function __invoke(DashboardScope $scope, DateRange $dateRange, bool $showCosts): DashboardPageResponse
    {
        $paginated = $scope->withoutShared($scope->calls($dateRange)->whereNotNull('trace_id'), 'trace_id')->toBase()
            ->select('trace_id')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw(DashboardScope::FAILED_SQL.' as failed')
            ->selectRaw(DashboardScope::COST_SQL.' as cost')
            ->selectRaw(DashboardScope::TOKENS_SQL.' as tokens')
            ->selectRaw('MIN(COALESCE(started_at, created_at)) as first_started_at')
            ->groupBy('trace_id')
            ->latest('first_started_at')
            ->orderBy('trace_id')
            ->paginate(25);

        /** @var array<int, stdClass> $traces */
        $traces = $paginated->items();

        $calls = $scope->calls()
            ->whereIn('trace_id', array_map(fn (stdClass $trace) => (string) $trace->trace_id, $traces))
            ->select(['trace_id', 'provider', 'model', 'latency_ms', 'started_at', 'created_at'])
            ->get()
            ->groupBy(fn (SpectraRequest $call) => (string) $call->trace_id);

        return new DashboardPageResponse(
            data: array_map(function (stdClass $trace) use ($calls, $showCosts) {
                $traceCalls = $calls->get((string) $trace->trace_id, collect());
                $starts = $traceCalls->map(fn (SpectraRequest $call): CarbonInterface => $call->started_at ?? $call->created_at ?? Date::now());
                $ends = $traceCalls->map(fn (SpectraRequest $call): int => (int) ($call->started_at ?? $call->created_at ?? Date::now())->getPreciseTimestamp(3) + ($call->latency_ms ?? 0));
                $firstStart = $starts->min();

                return [
                    'trace_id' => (string) $trace->trace_id,
                    'request_count' => (int) $trace->requests,
                    'failed_count' => (int) $trace->failed,
                    'total_cost_in_cents' => $showCosts ? (float) $trace->cost : null,
                    'total_tokens' => (int) $trace->tokens,
                    'started_at' => Date::parse((string) $trace->first_started_at)->format(self::ISO_MILLISECONDS),
                    'duration_ms' => $firstStart === null ? 0 : max(0, (int) ($ends->max() - $firstStart->getPreciseTimestamp(3))),
                    'models' => $traceCalls
                        ->unique(fn (SpectraRequest $call) => "{$call->provider}|{$call->model}")
                        ->map(fn (SpectraRequest $call) => ['provider' => $call->provider, 'model' => $call->model])
                        ->values()
                        ->all(),
                ];
            }, $traces),
            current_page: $paginated->currentPage(),
            last_page: $paginated->lastPage(),
            total: $paginated->total(),
        );
    }
}
