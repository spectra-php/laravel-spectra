<?php

declare(strict_types=1);

namespace Spectra\Actions\Costs;

use Illuminate\Support\Collection;
use Spectra\Data\CostByProvider;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * Cost per provider for the costs screen, over successful requests, most
 * expensive first.
 */
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
                ->successfulCost()
                ->orderByDesc('cost')
                ->get()
        );
    }
}
