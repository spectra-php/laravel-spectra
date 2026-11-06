<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Dashboards;

use Illuminate\Http\Request;
use Spectra\Actions\Dashboards\BuildDashboard;
use Spectra\Data\Responses\DashboardResponse;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Models\SpectraDashboard;
use Spectra\Support\Dashboards\DashboardAccess;
use Spectra\Support\Dashboards\DashboardTrackables;

/**
 * GET /api/dashboards/{id}/preview: exactly what the dashboard's viewer gets, for
 * an admin to check.
 */
class PreviewDashboardController extends BaseApiController
{
    /**
     * Get the dashboard's data as the trackable would see it.
     */
    public function __invoke(
        string $id,
        Request $request,
        DashboardTrackables $trackables,
        BuildDashboard $buildDashboard,
    ): DashboardResponse {
        abort_unless(DashboardAccess::allowed(), 403);

        $input = $request->validate([
            'period' => ['nullable', 'string', 'in:today,week,month,year'],
        ]);

        $dashboard = SpectraDashboard::query()->findOrFail($id);
        $trackable = $trackables->find($dashboard->trackable_type, $dashboard->trackable_id);

        abort_if($trackable === null, 404, 'No trackable of this type has this id.');

        return $buildDashboard($dashboard, $trackable, $input['period'] ?? $dashboard->default_period);
    }
}
