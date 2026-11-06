<?php

declare(strict_types=1);

namespace Spectra\Actions\Traces;

use Illuminate\Http\Request;
use Spectra\Data\TracesSummary;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Concerns\FiltersRequests;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RecentGroups;
use Spectra\Support\Query\RequestAggregateQuery;
use Spectra\Support\Query\RequestCounter;

/**
 * How many traces the filters keep, how many had a failed call, and what a
 * trace costs and takes on average.
 */
class GetTracesSummary
{
    use FiltersRequests;

    /**
     * Create a new get traces summary action instance.
     */
    public function __construct(
        private readonly BuildTraceQuery $buildTraceQuery,
        private readonly RecentGroups $recentGroups,
        private readonly RequestCounter $counter,
    ) {}

    /**
     * Get the trace count, failed count and average cost, calls and duration for the filters.
     */
    public function __invoke(Request $request, DateRange $dateRange): TracesSummary
    {
        if ($this->recentGroups->isLarge($dateRange)) {
            return $this->estimate($request, $dateRange);
        }

        $traces = ($this->buildTraceQuery)($request, $dateRange)->toQuery()->toBase();

        $totals = (new SpectraRequest)->getConnection()->query()
            ->fromSub($traces, 'traces')
            ->selectRaw('COUNT(*) as trace_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN failed_count > 0 THEN 1 ELSE 0 END), 0) as failed_trace_count')
            ->selectRaw('COALESCE(SUM(cost), 0) as total_cost')
            ->selectRaw('COALESCE(AVG(request_count), 0) as avg_request_count')
            ->selectRaw('COALESCE(AVG(duration_ms), 0) as avg_duration_ms')
            ->first();

        $traceCount = (int) ($totals->trace_count ?? 0);
        $totalCost = (float) ($totals->total_cost ?? 0);

        return new TracesSummary(
            trace_count: $traceCount,
            failed_trace_count: (int) ($totals->failed_trace_count ?? 0),
            total_cost_in_cents: $totalCost,
            avg_cost_in_cents: $traceCount > 0 ? $totalCost / $traceCount : 0.0,
            avg_request_count: round((float) ($totals->avg_request_count ?? 0), 1),
            avg_duration_ms: (int) round((float) ($totals->avg_duration_ms ?? 0)),
        );
    }

    /**
     * Scale the latest traces up to the range: the range's cost is known from
     * the daily stats when no filter needs individual calls, the rest is the
     * average of the sample.
     */
    private function estimate(Request $request, DateRange $dateRange): TracesSummary
    {
        $calls = $this->buildTraceQuery->matchingTraceIds($request, $dateRange);
        $count = $this->counter->count($calls, $request, $dateRange);
        $sample = $this->recentGroups->sample($calls, 'trace_id', $count->rows);

        $traces = RequestAggregateQuery::make($dateRange)
            ->forTraces($sample['ids'])
            ->groupByColumns('trace_id')
            ->callTotals()
            ->get();

        $sampled = max($traces->count(), 1);
        $sampleCost = (float) $traces->sum('cost');
        $totalCost = $count->isExact && ! $this->hasRequestFilters($request) && ! $request->filled('search')
            ? RequestAggregateQuery::fromRollups($dateRange)->sumCost()
            : $sampleCost / $sampled * $sample['estimate'];

        return new TracesSummary(
            trace_count: $sample['estimate'],
            failed_trace_count: (int) round($traces->where('failed_count', '>', 0)->count() / $sampled * $sample['estimate']),
            total_cost_in_cents: $totalCost,
            avg_cost_in_cents: $sample['estimate'] > 0 ? $totalCost / $sample['estimate'] : 0.0,
            avg_request_count: round((float) $traces->avg('request_count'), 1),
            avg_duration_ms: (int) round((float) $traces->avg('duration_ms')),
            estimated: true,
        );
    }
}
