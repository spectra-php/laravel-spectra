<?php

namespace Spectra\Actions\Costs;

use Spectra\Queries\TotalCostQuery;
use Spectra\Support\DateRange;

class GetTotalCost
{
    public function __construct(
        private readonly TotalCostQuery $query,
    ) {}

    public function __invoke(DateRange $dateRange): float
    {
        return ($this->query)($dateRange);
    }
}
