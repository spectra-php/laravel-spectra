<?php

namespace Spectra\Queries;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Concerns\FiltersTrackables;
use Spectra\Support\DateRange;

class TrackablesLatencyByModelTypeQuery
{
    use FiltersTrackables;

    /**
     * @return \Illuminate\Support\Collection<int, SpectraRequest>
     */
    public function __invoke(Request $request, DateRange $dateRange): Collection
    {
        $query = SpectraRequest::query()
            ->whereNotNull('trackable_type')
            ->whereNotNull('trackable_id');

        $dateRange->apply($query);
        $this->applyTrackableFilters($query, $request);

        return $query
            ->select('model_type')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('SUM(total_cost_in_cents) as cost')
            ->selectRaw('AVG(latency_ms) as avg_latency')
            ->groupBy('model_type')
            ->get();
    }
}
