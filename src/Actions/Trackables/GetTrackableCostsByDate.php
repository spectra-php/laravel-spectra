<?php

declare(strict_types=1);

namespace Spectra\Actions\Trackables;

use Illuminate\Support\Collection;
use Spectra\Data\CostByDate;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * One trackable's cost per day, over successful requests.
 */
class GetTrackableCostsByDate
{
    /**
     * Get the trackable's cost per day of successful requests in the date range.
     *
     * @return Collection<int, CostByDate>
     */
    public function __invoke(string $type, string $id, DateRange $dateRange): Collection
    {
        return CostByDate::fromCollection(
            RequestAggregateQuery::make($dateRange)
                ->forTrackable($type, $id)
                ->successfulOnly()
                ->byDate()
                ->successfulCost()
                ->get()
        );
    }
}
