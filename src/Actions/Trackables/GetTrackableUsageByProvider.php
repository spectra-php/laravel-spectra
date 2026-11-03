<?php

declare(strict_types=1);

namespace Spectra\Actions\Trackables;

use Illuminate\Support\Collection;
use Spectra\Data\UsageByProvider;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * One trackable's usage per provider, most expensive first; cost covers
 * successful requests.
 */
class GetTrackableUsageByProvider
{
    /**
     * @return Collection<int, UsageByProvider>
     */
    public function __invoke(string $type, string $id, DateRange $dateRange): Collection
    {
        return UsageByProvider::fromCollection(
            RequestAggregateQuery::make($dateRange)
                ->forTrackable($type, $id)
                ->groupByColumns('provider')
                ->countAs('requests')
                ->tokenSum()
                ->mediaSums()
                ->successfulCost()
                ->orderByDesc('cost')
                ->get()
        );
    }
}
