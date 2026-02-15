<?php

namespace Spectra\Queries;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;

class CostsByProviderQuery
{
    /**
     * @return \Illuminate\Support\Collection<int, SpectraRequest>
     */
    public function __invoke(DateRange $dateRange): Collection
    {
        $query = SpectraRequest::query()
            ->whereBetween('status_code', [200, 299]);

        $dateRange->apply($query);

        return $query
            ->select('provider')
            ->selectRaw('SUM(total_cost_in_cents) as cost_sum')
            ->groupBy('provider')
            ->orderByDesc('cost_sum')
            ->get();
    }
}
