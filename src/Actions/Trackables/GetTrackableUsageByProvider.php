<?php

namespace Spectra\Actions\Trackables;

use Illuminate\Support\Collection;
use Spectra\Data\UsageByProvider;
use Spectra\Queries\TrackableUsageByProviderQuery;
use Spectra\Support\DateRange;

class GetTrackableUsageByProvider
{
    public function __construct(
        private readonly TrackableUsageByProviderQuery $query,
    ) {}

    /**
     * @return \Illuminate\Support\Collection<int, UsageByProvider>
     */
    public function __invoke(string $type, string $id, DateRange $dateRange): Collection
    {
        return UsageByProvider::fromCollection(($this->query)($type, $id, $dateRange));
    }
}
