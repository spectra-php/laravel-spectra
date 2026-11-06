<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Dashboards;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Spectra\Actions\Dashboards\BuildDashboard;
use Spectra\Contracts\DashboardTrackableResolver;
use Spectra\Data\Responses\DashboardResponse;
use Spectra\Models\SpectraDashboard;
use Spectra\Support\Dashboards\DashboardAccess;

/**
 * GET /dashboards/{id}/data: a shared dashboard's widgets with their data. It is
 * not behind `viewSpectra`, because its viewers aren't dashboard users. The
 * trackable comes from the logged-in viewer and the saved dashboard, never from
 * the request, and the period is the only input.
 */
class DashboardDataController extends Controller
{
    /**
     * Get the dashboard's data for the trackable the viewer may see.
     */
    public function __invoke(
        string $id,
        Request $request,
        DashboardTrackableResolver $resolver,
        BuildDashboard $buildDashboard,
    ): DashboardResponse {
        abort_unless(DashboardAccess::enabled(), 404);

        $input = $request->validate([
            'period' => ['nullable', 'string', 'in:today,week,month,year'],
        ]);

        $dashboard = SpectraDashboard::query()->where('is_active', true)->findOrFail($id);
        $viewer = $request->user();

        abort_if($viewer === null, 403);

        $trackable = $resolver->resolve($viewer, $dashboard->trackable_type, $dashboard->trackable_id);

        abort_unless($trackable !== null && $dashboard->isFor($trackable), 403);

        return $buildDashboard($dashboard, $trackable, $input['period'] ?? $dashboard->default_period);
    }
}
