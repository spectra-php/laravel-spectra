<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Dashboards;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Spectra\Actions\Dashboards\Sections\GetDashboardTraces;
use Spectra\Data\Responses\DashboardPageResponse;
use Spectra\Enums\DashboardSection;
use Spectra\Models\SpectraDashboard;
use Spectra\Support\Dashboards\DashboardAccess;
use Spectra\Support\Dashboards\DashboardViewer;
use Spectra\Support\DateRange;

/**
 * GET /dashboards/{id}/data/traces: the viewer's traces. Not behind `viewSpectra`, because its
 * viewers aren't dashboard users. The scope comes only from the saved dashboard
 * and the logged-in viewer, never from the request.
 */
class DashboardTracesController extends Controller
{
    /**
     * Get one page of the viewer's traces.
     */
    public function __invoke(string $id, Request $request, DashboardViewer $viewer, GetDashboardTraces $getTraces): DashboardPageResponse
    {
        abort_unless(DashboardAccess::enabled(), 404);

        $input = $request->validate(['period' => ['nullable', 'string', 'in:today,week,month,year']]);

        $dashboard = SpectraDashboard::query()->where('is_active', true)->findOrFail($id);
        $scope = $viewer->scope($dashboard, $request->user());

        abort_if($scope === null, 403);
        abort_unless($dashboard->hasSection(DashboardSection::Traces), 404);

        return $getTraces($scope, DateRange::forPeriod($input['period'] ?? $dashboard->default_period), $dashboard->showsCosts());
    }
}
