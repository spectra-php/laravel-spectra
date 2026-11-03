<?php

declare(strict_types=1);

namespace Spectra\Actions\Trackables;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Spectra\Data\ModelTypeLatency;
use Spectra\Data\ModelTypeStat;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * The latency breakdown by model type behind the trackables list, over the
 * same filtered rows as the list itself.
 */
class GetTrackablesLatencyByModelType
{
    /**
     * @return Collection<int, ModelTypeLatency>
     */
    public function __invoke(Request $request, DateRange $dateRange): Collection
    {
        return ModelTypeLatency::fromCollection(ModelTypeStat::fromCollection(
            RequestAggregateQuery::make($dateRange)
                ->withTrackable()
                ->filteredBy($request)
                ->groupByColumns('model_type')
                ->countAs()
                ->successfulCost()
                ->successfulLatency()
                ->get(),
        ));
    }
}
