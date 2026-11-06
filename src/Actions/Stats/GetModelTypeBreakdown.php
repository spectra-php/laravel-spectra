<?php

declare(strict_types=1);

namespace Spectra\Actions\Stats;

use Illuminate\Support\Collection;
use Spectra\Data\ModelTypeStat;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * Requests, cost and latency per model type, the base of the overview's cost
 * and latency breakdowns.
 */
class GetModelTypeBreakdown
{
    /**
     * Get the requests, cost and latency per model type for the date range and layout.
     *
     * @return Collection<int, ModelTypeStat>
     */
    public function __invoke(DateRange $dateRange, string $layout): Collection
    {
        return ModelTypeStat::fromCollection(
            RequestAggregateQuery::fromRollups($dateRange)
                ->forLayout($layout)
                ->groupByColumns('model_type')
                ->countAs()
                ->successfulCost()
                ->successfulLatency()
                ->orderBy('model_type')
                ->get()
        );
    }
}
