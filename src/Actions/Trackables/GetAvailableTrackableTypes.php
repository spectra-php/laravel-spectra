<?php

declare(strict_types=1);

namespace Spectra\Actions\Trackables;

use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;

/**
 * The trackable types (morph classes) that made requests in the period, for
 * the trackables screen's type filter.
 */
class GetAvailableTrackableTypes
{
    /**
     * Get the trackable types that made requests in the date range.
     *
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
