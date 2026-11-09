<?php

declare(strict_types=1);

namespace Spectra\Actions\Costs;

use Illuminate\Support\Collection;
use Spectra\Data\CostByModelType;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * Requests, usage and cost per model type for the costs screen; counts and
 * usage include failures, cost covers successful requests.
 */
class GetCostsByModelType
{
    /**
     * Get the requests, usage and cost per model type for the date range.
     * Narrowed to one model when `$model` (and `$provider`) is given.
     *
     * @return Collection<int, CostByModelType>
     */
    public function __invoke(DateRange $dateRange, ?string $model = null, ?string $provider = null): Collection
    {
        return CostByModelType::fromCollection(
            RequestAggregateQuery::fromRollups($dateRange)
                ->forModel($model, $provider)
                ->groupByColumns('model_type')
                ->countAs('requests')
                ->tokenSum()
                ->usageSums()
                ->successfulCost()
                ->orderByDesc('cost')
                ->orderBy('model_type')
                ->get()
        );
    }
}
