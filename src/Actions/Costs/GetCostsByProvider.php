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
     * Get the cost per provider for the date range, most expensive first.
     * Narrowed to one model when `$model` (and `$provider`) is given.
     *
     * @return Collection<int, CostByProvider>
     */
    public function __invoke(DateRange $dateRange, ?string $model = null, ?string $provider = null): Collection
    {
        return CostByProvider::fromCollection(
            RequestAggregateQuery::fromRollups($dateRange)
                ->forModel($model, $provider)
                ->successfulOnly()
                ->groupByColumns('provider')
                ->successfulCost()
                ->orderByDesc('cost')
                ->orderBy('provider')
                ->get()
        );
    }
}
