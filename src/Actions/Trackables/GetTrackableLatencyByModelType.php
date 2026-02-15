<?php

namespace Spectra\Actions\Trackables;

use Illuminate\Support\Collection;
use Spectra\Data\ModelTypeStat;
use Spectra\Queries\TrackableLatencyByModelTypeQuery;
use Spectra\Support\DateRange;

class GetTrackableLatencyByModelType
{
    public function __construct(
        private readonly TrackableLatencyByModelTypeQuery $query,
    ) {}

    /**
     * @return \Illuminate\Support\Collection<int, ModelTypeStat>
     */
    public function __invoke(string $type, string $id, DateRange $dateRange): Collection
    {
        return ModelTypeStat::fromCollection(($this->query)($type, $id, $dateRange));
    }
}
