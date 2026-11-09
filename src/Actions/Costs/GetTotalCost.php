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
    /**
     * Get the total cost of successful requests in the date range.
     * Narrowed to one model when `$model` (and `$provider`) is given.
     */
    public function __invoke(DateRange $dateRange, ?string $model = null, ?string $provider = null): float
    {
        return RequestAggregateQuery::fromRollups($dateRange)
            ->forModel($model, $provider)
            ->successfulOnly()
            ->sumCost();
    }
}
