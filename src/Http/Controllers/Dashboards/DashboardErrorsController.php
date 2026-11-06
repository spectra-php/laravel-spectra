<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Dashboards;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Spectra\Actions\Dashboards\Sections\GetDashboardErrors;
use Spectra\Data\Responses\DashboardErrorsResponse;
use Spectra\Enums\DashboardSection;
use Spectra\Models\SpectraDashboard;
use Spectra\Support\Dashboards\DashboardAccess;
use Spectra\Support\Dashboards\DashboardViewer;
use Spectra\Support\DateRange;

/**
 * GET /dashboards/{id}/data/errors: the viewer's failures, grouped by cause. Not behind `viewSpectra`, because its
 * viewers aren't dashboard users. The scope comes only from the saved dashboard
 * and the logged-in viewer, never from the request.
 */
class DashboardErrorsController extends Controller
{
    /**
     * Get the viewer's failure totals and groups.
     */
    public function __invoke(string $id, Request $request, DashboardViewer $viewer, GetDashboardErrors $getErrors): DashboardErrorsResponse
    {
        abort_unless(DashboardAccess::enabled(), 404);

        $input = $request->validate(['period' => ['nullable', 'string', 'in:today,week,month,year']]);

        $dashboard = SpectraDashboard::query()->where('is_active', true)->findOrFail($id);
        $scope = $viewer->scope($dashboard, $request->user());

        abort_if($scope === null, 403);
        abort_unless($dashboard->hasSection(DashboardSection::Errors), 404);

        return $getErrors($scope, DateRange::forPeriod($input['period'] ?? $dashboard->default_period), $dashboard->show_messages);
    }
}
