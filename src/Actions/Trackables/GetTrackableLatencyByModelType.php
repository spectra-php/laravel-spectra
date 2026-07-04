<?php

declare(strict_types=1);

namespace Spectra\Actions\Trackables;

use Illuminate\Support\Collection;
use Spectra\Data\ModelTypeStat;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

class GetTrackableLatencyByModelType
{
    /**
     * @return Collection<int, ModelTypeStat>
     */
    public function __invoke(string $type, string $id, DateRange $dateRange): Collection
    {
        return ModelTypeStat::fromCollection(
            RequestAggregateQuery::make($dateRange)
                ->forTrackable($type, $id)
                ->groupByColumns('model_type')
                ->countAs()
                ->successfulCost()
                ->successfulLatency()
                ->get()
        );
    }
}
