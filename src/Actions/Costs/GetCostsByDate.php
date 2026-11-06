<?php

declare(strict_types=1);

namespace Spectra\Actions\Costs;

use Illuminate\Support\Collection;
use Spectra\Data\CostByDate;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * Cost per day for the costs screen's chart, over successful requests.
 */
class GetCostsByDate
{
    /**
     * Get the cost per day of successful requests in the date range.
     *
     * @return Collection<int, CostByDate>
     */
    public function __invoke(DateRange $dateRange): Collection
    {
        return CostByDate::fromCollection(
            RequestAggregateQuery::fromRollups($dateRange)
                ->successfulOnly()
                ->byDate()
                ->successfulCost()
                ->get()
        );
    }
}
