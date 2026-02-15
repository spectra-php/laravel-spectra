<?php

namespace Spectra\Actions\Stats;

use Illuminate\Support\Collection;
use Spectra\Data\ModelTypeStat;
use Spectra\Queries\ModelTypeBreakdownQuery;
use Spectra\Support\DateRange;

class GetModelTypeBreakdown
{
    public function __construct(
        private readonly ModelTypeBreakdownQuery $query,
    ) {}

    /**
     * @return \Illuminate\Support\Collection<int, ModelTypeStat>
     */
    public function __invoke(DateRange $dateRange, string $layout): Collection
    {
        return ModelTypeStat::fromCollection(($this->query)($dateRange, $layout));
    }
}
