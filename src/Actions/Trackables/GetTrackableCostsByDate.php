<?php

namespace Spectra\Actions\Trackables;

use Illuminate\Support\Collection;
use Spectra\Data\CostByDate;
use Spectra\Queries\TrackableCostsByDateQuery;
use Spectra\Support\DateRange;

class GetTrackableCostsByDate
{
    public function __construct(
        private readonly TrackableCostsByDateQuery $query,
    ) {}

    /**
     * @return \Illuminate\Support\Collection<int, CostByDate>
     */
    public function __invoke(string $type, string $id, DateRange $dateRange): Collection
    {
        return CostByDate::fromCollection(($this->query)($type, $id, $dateRange));
    }
}
