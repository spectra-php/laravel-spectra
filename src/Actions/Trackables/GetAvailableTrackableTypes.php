<?php

namespace Spectra\Actions\Trackables;

use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;

class GetAvailableTrackableTypes
{
    /**
     * @return array<int, string>
     */
    public function __invoke(DateRange $dateRange): array
    {
        $query = SpectraRequest::query()
            ->whereNotNull('trackable_type')
            ->select('trackable_type')
            ->distinct();

        $dateRange->apply($query);

        return $query->pluck('trackable_type')->toArray();
    }
}
