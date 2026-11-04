<?php

declare(strict_types=1);

namespace Spectra\Actions\Traces;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Concerns\FiltersRequests;
use Spectra\Support\DateRange;
use Spectra\Support\Pricing\PricingLookup;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * The traces screen's query: one row of totals per trace, over the calls in
 * the date range. The traces list pages it and the summary totals it, so
 * both describe the same traces.
 *
 * Filters keep the traces that hold a matching call, with all their calls
 * in the totals: filtering by a model shows every trace that used it, at
 * its full cost. Search matches a trace id exactly or a call's origin.
 */
class BuildTraceQuery
{
    use FiltersRequests;

    /**
     * Create a new build trace query action instance.
     */
    public function __construct(
        private readonly PricingLookup $pricingLookup,
    ) {}

    /**
     * Get the per-trace totals query for the filters and date range.
     */
    public function __invoke(Request $request, DateRange $dateRange): RequestAggregateQuery
    {
        $query = RequestAggregateQuery::make($dateRange)->withTrace();

        if ($request->filled('search') || $this->hasRequestFilters($request)) {
            $query->inTraces($this->matchingTraceIds($request, $dateRange));
        }

        return $query->groupByColumns('trace_id')->callTotals();
    }

    /**
     * Build the subquery of trace ids that hold a call matching the filters, origin or id.
     *
     * @return Builder<SpectraRequest>
     */
    private function matchingTraceIds(Request $request, DateRange $dateRange): Builder
    {
        $calls = SpectraRequest::query()->select('trace_id')->whereNotNull('trace_id');

        $dateRange->apply($calls);
        $this->applyRequestFilters($calls, $request, $this->pricingLookup);

        if ($request->filled('search')) {
            $search = (string) $request->input('search');

            // trace_id is a native uuid column on PostgreSQL, which errors on
            // any other string, so only compare it with a UUID.
            $calls->where(fn (Builder $match) => $match
                ->where('origin', 'like', "%{$search}%")
                ->when(Str::isUuid($search), fn (Builder $uuid) => $uuid->orWhere('trace_id', $search)));
        }

        return $calls;
    }
}
