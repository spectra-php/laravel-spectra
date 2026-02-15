<?php

namespace Spectra\Actions\Trackables;

use Illuminate\Support\Collection;
use Spectra\Data\UsageByModel;
use Spectra\Queries\TrackableUsageByModelQuery;
use Spectra\Support\DateRange;

class GetTrackableUsageByModel
{
    public function __construct(
        private readonly TrackableUsageByModelQuery $query,
    ) {}

    /**
     * @return \Illuminate\Support\Collection<int, UsageByModel>
     */
    public function __invoke(string $type, string $id, DateRange $dateRange): Collection
    {
        return UsageByModel::fromCollection(($this->query)($type, $id, $dateRange));
    }
}
