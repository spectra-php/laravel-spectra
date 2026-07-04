<?php

declare(strict_types=1);

namespace Spectra\Actions\Costs;

use Illuminate\Support\Collection;
use Spectra\Data\CostByProvider;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

class GetCostsByProvider
{
    /**
     * @return Collection<int, CostByProvider>
     */
    public function __invoke(DateRange $dateRange): Collection
    {
        return CostByProvider::fromCollection(
            RequestAggregateQuery::make($dateRange)
                ->successfulOnly()
                ->groupByColumns('provider')
                ->selectRaw('SUM(total_cost_in_cents) as cost')
                ->orderByDesc('cost')
                ->get()
        );
    }
}
