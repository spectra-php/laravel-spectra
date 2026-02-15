<?php

namespace Spectra\Actions\Trackables;

use Illuminate\Support\Collection;
use Spectra\Data\RequestByDate;
use Spectra\Queries\TrackableRequestsByDateQuery;
use Spectra\Support\DateRange;

class GetTrackableRequestsByDate
{
    public function __construct(
        private readonly TrackableRequestsByDateQuery $query,
    ) {}

    /**
     * @return \Illuminate\Support\Collection<int, RequestByDate>
     */
    public function __invoke(string $type, string $id, DateRange $dateRange): Collection
    {
        return RequestByDate::fromCollection(($this->query)($type, $id, $dateRange));
    }
}
