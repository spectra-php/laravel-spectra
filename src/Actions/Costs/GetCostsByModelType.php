<?php

declare(strict_types=1);

namespace Spectra\Actions\Costs;

use Illuminate\Support\Collection;
use Spectra\Data\CostByModelType;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

class GetCostsByModelType
{
    /**
     * @return Collection<int, CostByModelType>
     */
    public function __invoke(DateRange $dateRange): Collection
    {
        return CostByModelType::fromCollection(
            RequestAggregateQuery::make($dateRange)
                ->successfulOnly()
                ->groupByColumns('model_type')
                ->countAs('requests')
                ->tokenSum()
                ->usageSums()
                ->selectRaw('SUM(total_cost_in_cents) as cost')
                ->orderByDesc('cost')
                ->get()
        );
    }
}
