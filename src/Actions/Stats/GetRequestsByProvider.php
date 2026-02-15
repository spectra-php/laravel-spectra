<?php

namespace Spectra\Actions\Stats;

use Illuminate\Support\Collection;
use Spectra\Data\RequestByProvider;
use Spectra\Queries\RequestsByProviderQuery;
use Spectra\Support\DateRange;

class GetRequestsByProvider
{
    public function __construct(
        private readonly RequestsByProviderQuery $query,
    ) {}

    /**
     * @return \Illuminate\Support\Collection<int, RequestByProvider>
     */
    public function __invoke(DateRange $dateRange, string $layout): Collection
    {
        return RequestByProvider::fromCollection(($this->query)($dateRange, $layout));
    }
}
