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
 * GET /api/dashboards/{id}/preview: exactly what a viewer gets, for an
 * admin to check. A dashboard for every trackable of a type previews the
 * `trackable_id` the admin gives, because it has no owner of its own.
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
            'trackable_id' => ['nullable', 'string', 'max:255'],
            'period' => ['nullable', 'string', 'in:today,week,month,year'],
        ]);

        $dashboard = SpectraDashboard::query()->findOrFail($id);
        $trackableId = $dashboard->trackable_id ?? $input['trackable_id'] ?? null;

        abort_if($trackableId === null, 422, 'Enter a trackable id to preview this dashboard.');

        $trackable = $trackables->find($dashboard->trackable_type, $trackableId);

        abort_if($trackable === null, 404, 'No trackable of this type has this id.');

        return $buildDashboard($dashboard, $trackable, $input['period'] ?? $dashboard->default_period);
    }
}
