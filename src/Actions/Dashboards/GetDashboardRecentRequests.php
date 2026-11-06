<?php

declare(strict_types=1);

namespace Spectra\Actions\Dashboards;

use Illuminate\Support\Collection;
use Spectra\Data\DashboardRequest;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;

/**
 * A trackable's latest calls for a shared dashboard. The columns are a fixed
 * list, so a prompt, a response, metadata or a trace can never reach a viewer.
 */
class GetDashboardRecentRequests
{
    /**
     * Get the trackable's latest calls in the date range, newest first.
     *
     * @return Collection<int, DashboardRequest>
     */
    public function __invoke(string $type, string $id, DateRange $dateRange): Collection
    {
        return SpectraRequest::query()
            ->select(DashboardRequest::COLUMNS)
            ->where('trackable_type', $type)
            ->where('trackable_id', $id)
            ->tap(fn ($query) => $dateRange->apply($query))
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (SpectraRequest $request) => DashboardRequest::fromModel($request))
            ->values();
    }
}
