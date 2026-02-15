<?php

namespace Spectra\Actions\Costs;

use Illuminate\Support\Collection;
use Spectra\Data\CostByProvider;
use Spectra\Queries\CostsByProviderQuery;
use Spectra\Support\DateRange;

class GetCostsByProvider
{
    public function __construct(
        private readonly CostsByProviderQuery $query,
    ) {}

    /**
     * @return \Illuminate\Support\Collection<int, CostByProvider>
     */
    public function __invoke(DateRange $dateRange): Collection
    {
        return CostByProvider::fromCollection(($this->query)($dateRange));
    }
}
