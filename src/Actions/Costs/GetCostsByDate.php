<?php

declare(strict_types=1);

namespace Spectra\Actions\Costs;

use Illuminate\Support\Collection;
use Spectra\Data\CostByDate;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

class GetCostsByDate
{
    /**
     * @return Collection<int, CostByDate>
     */
    public function __invoke(DateRange $dateRange): Collection
    {
        return CostByDate::fromCollection(
            RequestAggregateQuery::make($dateRange)
                ->successfulOnly()
                ->byDate()
                ->selectRaw('SUM(total_cost_in_cents) as cost')
                ->get()
        );
    }
}
