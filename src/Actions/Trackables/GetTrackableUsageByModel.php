<?php

declare(strict_types=1);

namespace Spectra\Actions\Trackables;

use Illuminate\Support\Collection;
use Spectra\Data\UsageByModel;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * One trackable's usage per provider and model, most used first; cost covers
 * successful requests.
 */
class GetTrackableUsageByModel
{
    /**
     * Get the trackable's requests, tokens and cost per provider and model, most used first.
     *
     * @return Collection<int, UsageByModel>
     */
    public function __invoke(string $type, string $id, DateRange $dateRange): Collection
    {
        return UsageByModel::fromCollection(
            RequestAggregateQuery::fromRollups($dateRange)
                ->forTrackable($type, $id)
                ->groupByColumns('provider', 'model')
                ->countAs('requests')
                ->tokenSum()
                ->successfulCost()
                ->orderByDesc('requests')
                ->orderBy('model')
                ->get()
        );
    }
}
