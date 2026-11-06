<?php

declare(strict_types=1);

namespace Spectra\Actions\Trackables;

use Illuminate\Support\Collection;
use Spectra\Data\RequestByDate;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * One trackable's requests per day, failures included.
 */
class GetTrackableRequestsByDate
{
    /**
     * Get the trackable's request count per day in the date range.
     *
     * @return Collection<int, RequestByDate>
     */
    public function __invoke(string $type, string $id, DateRange $dateRange): Collection
    {
        return RequestByDate::fromCollection(
            RequestAggregateQuery::fromRollups($dateRange)
                ->forTrackable($type, $id)
                ->byDate()
                ->countAs()
                ->get()
        );
    }
}
