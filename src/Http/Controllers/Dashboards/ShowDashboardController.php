<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Dashboards;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Spectra\Enums\DashboardSection;
use Spectra\Models\SpectraDashboard;
use Spectra\Support\Dashboards\DashboardAccess;
use Spectra\Support\Dashboards\DashboardViewer;

/**
 * GET /dashboards/{id} and its sections: the page a shared dashboard's viewer
 * opens. It is not behind `viewSpectra`, because its viewers aren't dashboard
 * users; it checks them against the saved dashboard instead, and loads none of
 * the admin screens.
 */
class ShowDashboardController extends Controller
{
    /**
     * Render the page for a viewer the dashboard allows.
     */
    public function __invoke(string $id, Request $request, DashboardViewer $viewer, ?string $section = null): View
    {
        abort_unless(DashboardAccess::enabled(), 404);

        $dashboard = SpectraDashboard::query()->where('is_active', true)->findOrFail($id);
        $scope = $viewer->scope($dashboard, $request->user());

        abort_if($scope === null, 403);
        abort_if($section !== null && ! $dashboard->hasSection(DashboardSection::from($section)), 404);

        return view('spectra::viewer', ['dashboard' => $dashboard, 'trackable' => $scope->trackable]);
    }
}
