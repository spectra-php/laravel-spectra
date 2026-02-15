<?php

namespace Spectra\Queries;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;

class TrackableCostsByDateQuery
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
            ->whereBetween('status_code', [200, 299])
            ->selectRaw('DATE(created_at) as date')
            ->selectRaw('SUM(total_cost_in_cents) as cost_sum')
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }
}
