<?php

declare(strict_types=1);

namespace Spectra\Actions\Stats;

use Illuminate\Support\Collection;
use Spectra\Data\RequestByDate;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * Requests per day for the overview chart, failures included.
 */
class GetRequestsByDate
{
    /**
     * @return Collection<int, RequestByDate>
     */
    public function __invoke(DateRange $dateRange, string $layout): Collection
    {
        return RequestByDate::fromCollection(
            RequestAggregateQuery::make($dateRange)
                ->forLayout($layout)
                ->byDate()
                ->countAs()
                ->get()
        );
    }
}
