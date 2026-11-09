<?php

declare(strict_types=1);

namespace Spectra\Actions\Costs;

use Illuminate\Support\Collection;
use Spectra\Data\CostModelOption;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * The models the costs screen's model filter offers: every provider and model
 * called in the period, most expensive first. Never narrowed by the filter
 * itself, so the other models stay on offer while one is picked.
 */
class GetCostModels
{
    /**
     * Get the provider and model pairs called in the date range, with their cost.
     *
     * @return Collection<int, CostModelOption>
     */
    public function __invoke(DateRange $dateRange, int $limit = 500): Collection
    {
        return CostModelOption::fromCollection(
            RequestAggregateQuery::fromRollups($dateRange)
                ->groupByColumns('provider', 'model')
                ->successfulCost()
                ->orderByDesc('cost')
                ->orderBy('model')
                ->orderBy('provider')
                ->limit($limit)
                ->get()
        );
    }
}
