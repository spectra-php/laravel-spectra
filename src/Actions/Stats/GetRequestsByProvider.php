<?php

declare(strict_types=1);

namespace Spectra\Actions\Stats;

use Illuminate\Support\Collection;
use Spectra\Data\RequestByProvider;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

class GetRequestsByProvider
{
    /**
     * @return Collection<int, RequestByProvider>
     */
    public function __invoke(DateRange $dateRange, string $layout): Collection
    {
        return RequestByProvider::fromCollection(
            RequestAggregateQuery::make($dateRange)
                ->forLayout($layout)
                ->groupByColumns('provider')
                ->countAs()
                ->get()
        );
    }
}
