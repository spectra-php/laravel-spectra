<?php

declare(strict_types=1);

namespace Spectra\Actions\Costs;

use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * The total spend for the selected period, over successful requests.
 */
class GetTotalCost
{
    public function __invoke(DateRange $dateRange): float
    {
        return RequestAggregateQuery::make($dateRange)
            ->successfulOnly()
            ->sumCost();
    }
}
