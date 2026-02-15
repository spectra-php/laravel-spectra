<?php

namespace Spectra\Queries;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;

class TrackableUsageByModelQuery
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
            ->select('model')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('SUM(prompt_tokens + completion_tokens) as tokens_sum')
            ->selectRaw('SUM(total_cost_in_cents) as cost')
            ->groupBy('model')
            ->orderByDesc('requests')
            ->get();
    }
}
