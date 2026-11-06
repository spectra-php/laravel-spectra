<?php

declare(strict_types=1);

namespace Spectra\Actions\Dashboards\Sections;

use Spectra\Data\DashboardRequest;
use Spectra\Data\Responses\DashboardPageResponse;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Dashboards\DashboardScope;
use Spectra\Support\DateRange;

/**
 * The trackable's calls for a shared dashboard's Requests page, newest first. A
 * row holds the call's id only when it can be opened, on a dashboard that shows
 * messages.
 */
class GetDashboardCalls
{
    /**
     * Get one page of the trackable's calls in the date range.
     */
    public function __invoke(DashboardScope $scope, DateRange $dateRange, bool $showMessages): DashboardPageResponse
    {
        $paginated = $scope->calls($dateRange)
            ->select(DashboardRequest::COLUMNS)
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        /** @var array<int, SpectraRequest> $calls */
        $calls = $paginated->items();

        return new DashboardPageResponse(
            data: array_map(fn (SpectraRequest $call) => DashboardRequest::fromModel($call, $showMessages), $calls),
            current_page: $paginated->currentPage(),
            last_page: $paginated->lastPage(),
            total: $paginated->total(),
        );
    }
}
