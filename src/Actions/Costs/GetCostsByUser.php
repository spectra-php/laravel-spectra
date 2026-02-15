<?php

namespace Spectra\Actions\Costs;

use Illuminate\Support\Collection;
use Spectra\Data\CostByUser;
use Spectra\Queries\CostsByUserQuery;
use Spectra\Support\DateRange;

class GetCostsByUser
{
    public function __construct(
        private readonly CostsByUserQuery $query,
    ) {}

    /**
     * @return \Illuminate\Support\Collection<int, CostByUser>
     */
    public function __invoke(DateRange $dateRange, int $limit = 10): Collection
    {
        return CostByUser::fromCollection(($this->query)($dateRange, $limit));
    }
}
