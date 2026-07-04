<?php

declare(strict_types=1);

namespace Spectra\Actions\Trackables;

use Illuminate\Support\Collection;
use Spectra\Data\CostByDate;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

class GetTrackableCostsByDate
{
    /**
     * @return Collection<int, CostByDate>
     */
    public function __invoke(string $type, string $id, DateRange $dateRange): Collection
    {
        return CostByDate::fromCollection(
            RequestAggregateQuery::make($dateRange)
                ->forTrackable($type, $id)
                ->successfulOnly()
                ->byDate()
                ->selectRaw('SUM(total_cost_in_cents) as cost')
                ->get()
        );
    }
}
