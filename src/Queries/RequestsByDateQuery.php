<?php

namespace Spectra\Queries;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Concerns\FiltersByLayout;
use Spectra\Support\DateRange;

class RequestsByDateQuery
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
            ->selectRaw('DATE(created_at) as date')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }
}
