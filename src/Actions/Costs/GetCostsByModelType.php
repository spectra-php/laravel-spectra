<?php

declare(strict_types=1);

namespace Spectra\Actions\Costs;

use Illuminate\Support\Collection;
use Spectra\Data\CostByModelType;
use Spectra\Queries\CostsByModelTypeQuery;
use Spectra\Support\DateRange;

class GetCostsByModelType
{
    public function __construct(
        private readonly CostsByModelTypeQuery $query,
    ) {}

    /**
     * @return Collection<int, CostByModelType>
     */
    public function __invoke(DateRange $dateRange): Collection
    {
        return CostByModelType::fromCollection(($this->query)($dateRange));
    }
}
