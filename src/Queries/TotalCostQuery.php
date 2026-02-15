<?php

namespace Spectra\Queries;

use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;

class TotalCostQuery
{
    public function __invoke(DateRange $dateRange): float
    {
        $query = SpectraRequest::query()
            ->whereBetween('status_code', [200, 299]);

        if ($dateRange->start !== null) {
            $query->where('created_at', '>=', $dateRange->start);
        }

        if ($dateRange->end !== null) {
            $query->where('created_at', '<=', $dateRange->end);
        }

        return (float) $query->sum('total_cost_in_cents');
    }
}
