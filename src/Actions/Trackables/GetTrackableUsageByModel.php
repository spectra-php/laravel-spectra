<?php

declare(strict_types=1);

namespace Spectra\Actions\Trackables;

use Illuminate\Support\Collection;
use Spectra\Data\UsageByModel;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

class GetTrackableUsageByModel
{
    /**
     * @return Collection<int, UsageByModel>
     */
    public function __invoke(string $type, string $id, DateRange $dateRange): Collection
    {
        return UsageByModel::fromCollection(
            RequestAggregateQuery::make($dateRange)
                ->forTrackable($type, $id)
                ->groupByColumns('model')
                ->countAs('requests')
                ->tokenSum()
                ->successfulCost()
                ->orderByDesc('requests')
                ->get()
        );
    }
}
