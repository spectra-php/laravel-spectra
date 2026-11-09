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
     * Narrowed to one model when `$model` (and `$provider`) is given.
     *
     * @return Collection<int, CostByDate>
     */
    public function __invoke(DateRange $dateRange, ?string $model = null, ?string $provider = null): Collection
    {
        return CostByDate::fromCollection(
            RequestAggregateQuery::fromRollups($dateRange)
                ->forModel($model, $provider)
                ->successfulOnly()
                ->byDate()
                ->successfulCost()
                ->get()
        );
    }
}
