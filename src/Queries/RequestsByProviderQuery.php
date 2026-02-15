<?php

namespace Spectra\Queries;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Concerns\FiltersByLayout;
use Spectra\Support\DateRange;

class RequestsByProviderQuery
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
            ->select('provider')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('provider')
            ->get();
    }
}
