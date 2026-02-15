<?php

namespace Spectra\Actions\Costs;

use Illuminate\Support\Collection;
use Spectra\Data\CostByDate;
use Spectra\Queries\CostsByDateQuery;
use Spectra\Support\DateRange;

class GetCostsByDate
{
    public function __construct(
        private readonly CostsByDateQuery $query,
    ) {}

    /**
     * @return \Illuminate\Support\Collection<int, CostByDate>
     */
    public function __invoke(DateRange $dateRange): Collection
    {
        return CostByDate::fromCollection(($this->query)($dateRange));
    }
}
