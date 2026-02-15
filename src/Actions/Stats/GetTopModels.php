<?php

namespace Spectra\Actions\Stats;

use Illuminate\Support\Collection;
use Spectra\Data\TopModel;
use Spectra\Queries\TopModelsQuery;
use Spectra\Support\DateRange;

class GetTopModels
{
    public function __construct(
        private readonly TopModelsQuery $query,
    ) {}

    /**
     * @return \Illuminate\Support\Collection<int, TopModel>
     */
    public function __invoke(DateRange $dateRange, string $layout, int $limit = 5): Collection
    {
        return TopModel::fromCollection(($this->query)($dateRange, $layout, $limit));
    }
}
