<?php

declare(strict_types=1);

namespace Spectra\Actions\Traces;

use Illuminate\Http\Request;
use Spectra\Data\TracesSummary;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;

/**
 * How many traces the filters keep, how many had a failed call, and what a
 * trace costs and takes on average.
 */
class GetTracesSummary
{
    public function __construct(
        private readonly BuildTraceQuery $buildTraceQuery,
    ) {}

    public function __invoke(Request $request, DateRange $dateRange): TracesSummary
    {
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
}
