<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Dashboards;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Spectra\Actions\Dashboards\Sections\GetDashboardConversations;
use Spectra\Data\Responses\DashboardPageResponse;
use Spectra\Enums\DashboardSection;
use Spectra\Models\SpectraDashboard;
use Spectra\Support\Dashboards\DashboardAccess;
use Spectra\Support\Dashboards\DashboardViewer;
use Spectra\Support\DateRange;

/**
 * GET /dashboards/{id}/data/conversations: the viewer's conversations. Not behind `viewSpectra`, because its
 * viewers aren't dashboard users. The scope comes only from the saved dashboard
 * and the logged-in viewer, never from the request.
 */
class DashboardConversationsController extends Controller
{
    /**
     * Get one page of the viewer's conversations.
     */
    public function __invoke(string $id, Request $request, DashboardViewer $viewer, GetDashboardConversations $getConversations): DashboardPageResponse
    {
        abort_unless(DashboardAccess::enabled(), 404);

        $input = $request->validate(['period' => ['nullable', 'string', 'in:today,week,month,year']]);

        $dashboard = SpectraDashboard::query()->where('is_active', true)->findOrFail($id);
        $scope = $viewer->scope($dashboard, $request->user());

        abort_if($scope === null, 403);
        abort_unless($dashboard->hasSection(DashboardSection::Conversations), 404);

        return $getConversations($scope, DateRange::forPeriod($input['period'] ?? $dashboard->default_period), $dashboard->show_costs);
    }
}
