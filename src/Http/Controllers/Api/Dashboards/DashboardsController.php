<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Dashboards;

use Spectra\Actions\Dashboards\GetDashboards;
use Spectra\Actions\Trackables\GetAvailableTrackableTypes;
use Spectra\Data\Responses\DashboardsResponse;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Support\Dashboards\DashboardAccess;

/**
 * GET /api/dashboards: the admin screen. Anyone who may view the
 * dashboard sees whether shared dashboards are on, but who has one, and what is
 * on it, stays with those who may design them.
 */
class DashboardsController extends BaseApiController
{
    /**
     * Get the saved dashboards and the trackable types to choose from.
     */
    public function __invoke(
        GetDashboards $getDashboards,
        GetAvailableTrackableTypes $getAvailableTrackableTypes,
    ): DashboardsResponse {
        $allowed = DashboardAccess::allowed();
        $migrated = $allowed && DashboardAccess::migrated();

        return new DashboardsResponse(
            enabled: DashboardAccess::enabled(),
            allowed: $allowed,
            migrated: $migrated,
            dashboards: $migrated ? $getDashboards() : collect(),
            trackable_types: $allowed ? $getAvailableTrackableTypes() : [],
        );
    }
}
