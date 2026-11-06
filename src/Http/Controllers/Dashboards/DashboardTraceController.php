<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Dashboards;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Spectra\Actions\Dashboards\Sections\GetDashboardTrace;
use Spectra\Data\Responses\DashboardTraceResponse;
use Spectra\Enums\DashboardSection;
use Spectra\Models\SpectraDashboard;
use Spectra\Support\Dashboards\DashboardAccess;
use Spectra\Support\Dashboards\DashboardViewer;

/**
 * GET /dashboards/{id}/data/traces/{traceId}: one of the viewer's traces. Not behind `viewSpectra`, because its
 * viewers aren't dashboard users. The scope comes only from the saved dashboard
 * and the logged-in viewer, never from the request.
 */
class DashboardTraceController extends Controller
{
    /**
     * Get the trace when every call in it is the viewer's.
     */
    public function __invoke(string $id, string $traceId, Request $request, DashboardViewer $viewer, GetDashboardTrace $getTrace): DashboardTraceResponse
    {
        abort_unless(DashboardAccess::enabled(), 404);

        $dashboard = SpectraDashboard::query()->where('is_active', true)->findOrFail($id);
        $scope = $viewer->scope($dashboard, $request->user());

        abort_if($scope === null, 403);
        abort_unless($dashboard->hasSection(DashboardSection::Traces), 404);

        $trace = $getTrace($scope, $traceId, $dashboard->showsCosts(), $dashboard->show_messages);

        abort_if($trace === null, 404);

        return $trace;
    }
}
