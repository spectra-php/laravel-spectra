<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Dashboards;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Spectra\Contracts\DashboardTrackableResolver;
use Spectra\Models\SpectraDashboard;
use Spectra\Support\Dashboards\DashboardAccess;

/**
 * GET /dashboards/{id}: the page a shared dashboard's viewer opens. It is not
 * behind `viewSpectra`, because its viewers aren't dashboard users; it checks
 * them against the saved dashboard instead, and loads none of the admin screens.
 */
class ShowDashboardController extends Controller
{
    /**
     * Render the page for a viewer the dashboard allows.
     */
    public function __invoke(string $id, Request $request, DashboardTrackableResolver $resolver): View
    {
        abort_unless(DashboardAccess::enabled(), 404);

        $dashboard = SpectraDashboard::query()->where('is_active', true)->findOrFail($id);
        $viewer = $request->user();

        abort_if($viewer === null, 403);

        $trackable = $resolver->resolve($viewer, $dashboard->trackable_type, $dashboard->trackable_id);

        abort_unless($trackable !== null && $dashboard->isFor($trackable), 403);

        return view('spectra::viewer', ['dashboard' => $dashboard]);
    }
}
