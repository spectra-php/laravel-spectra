<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Illuminate\Http\Request;
use Spectra\Actions\Requests\GetAvailableFilters;
use Spectra\Actions\Traces\FormatTracesList;
use Spectra\Actions\Traces\GetFilteredTraces;
use Spectra\Actions\Traces\GetTracesSummary;
use Spectra\Data\Responses\TracesResponse;
use Spectra\Support\DateRange;
use Spectra\Support\Query\TraceSchema;

/**
 * GET /api/traces: the traces screen, one filtered and sorted page of traces
 * with their totals and the filter options.
 */
class TracesController extends BaseApiController
{
    /**
     * Get a filtered, sorted page of traces with their summary and filter options.
     */
    public function __invoke(
        Request $request,
        GetFilteredTraces $getFilteredTraces,
        GetTracesSummary $getTracesSummary,
        GetAvailableFilters $getAvailableFilters,
        FormatTracesList $formatTracesList,
        TraceSchema $traceSchema,
    ): TracesResponse {
        $filters = $getAvailableFilters();

        if (! $traceSchema->hasTraceColumns()) {
            return TracesResponse::unmigrated($filters);
        }

        $dateRange = DateRange::fromRequest($request);
        $paginated = $getFilteredTraces($request, $dateRange);

        return new TracesResponse(
            data: $formatTracesList($paginated),
            current_page: $paginated->currentPage(),
            last_page: $paginated->lastPage(),
            total: $paginated->total(),
            summary: $getTracesSummary($request, $dateRange),
            available_tags: $filters->tags,
            available_finish_reasons: $filters->finish_reasons,
            available_reasoning_efforts: $filters->reasoning_efforts,
        );
    }
}
