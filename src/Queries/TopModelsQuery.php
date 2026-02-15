<?php

namespace Spectra\Queries;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Concerns\FiltersByLayout;
use Spectra\Support\DateRange;

class TopModelsQuery
{
    use FiltersByLayout;

    /**
     * @return \Illuminate\Support\Collection<int, SpectraRequest>
     */
    public function __invoke(DateRange $dateRange, string $layout, int $limit = 5): Collection
    {
        $query = SpectraRequest::query();
        $dateRange->apply($query);
        $this->applyLayoutFilter($query, $layout);

        return $query
            ->select('model', 'provider')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('SUM(total_cost_in_cents) as cost')
            ->groupBy('model', 'provider')
            ->orderByDesc('count')
            ->limit($limit)
            ->get();
    }
}
