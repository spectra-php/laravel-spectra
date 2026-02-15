<?php

namespace Spectra\Queries;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;

class TrackableLatencyByModelTypeQuery
{
    /**
     * @return \Illuminate\Support\Collection<int, SpectraRequest>
     */
    public function __invoke(string $type, string $id, DateRange $dateRange): Collection
    {
        $query = SpectraRequest::query()
            ->where('trackable_type', $type)
            ->where('trackable_id', $id);

        $dateRange->apply($query);

        return $query
            ->select('model_type')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('SUM(total_cost_in_cents) as cost')
            ->selectRaw('AVG(latency_ms) as avg_latency')
            ->groupBy('model_type')
            ->get();
    }
}
