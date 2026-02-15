<?php

namespace Spectra\Queries;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;

class CostsByUserQuery
{
    /**
     * @return \Illuminate\Support\Collection<int, SpectraRequest>
     */
    public function __invoke(DateRange $dateRange, int $limit = 10): Collection
    {
        $query = SpectraRequest::query()
            ->whereBetween('status_code', [200, 299])
            ->whereNotNull('trackable_id');

        $dateRange->apply($query);

        return $query
            ->select('trackable_id as user_id')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('SUM(total_cost_in_cents) as cost_sum')
            ->groupBy('trackable_id')
            ->orderByDesc('cost_sum')
            ->limit($limit)
            ->get();
    }
}
