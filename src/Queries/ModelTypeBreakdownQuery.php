<?php

namespace Spectra\Queries;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Concerns\FiltersByLayout;
use Spectra\Support\DateRange;

class ModelTypeBreakdownQuery
{
    use FiltersByLayout;

    /**
     * @return \Illuminate\Support\Collection<int, SpectraRequest>
     */
    public function __invoke(DateRange $dateRange, string $layout): Collection
    {
        $query = SpectraRequest::query();
        $dateRange->apply($query);
        $this->applyLayoutFilter($query, $layout);

        return $query
            ->select('model_type')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('SUM(total_cost_in_cents) as cost')
            ->selectRaw('AVG(latency_ms) as avg_latency')
            ->groupBy('model_type')
            ->get();
    }
}
