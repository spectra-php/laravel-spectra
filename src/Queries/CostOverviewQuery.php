<?php

namespace Spectra\Queries;

use Illuminate\Support\Carbon;
use Spectra\Models\SpectraRequest;

class CostOverviewQuery
{
    public function __invoke(Carbon $from, ?Carbon $to = null): float
    {
        $query = SpectraRequest::query()
            ->whereBetween('status_code', [200, 299])
            ->where('created_at', '>=', $from);

        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }

        return (float) $query->sum('total_cost_in_cents');
    }
}
