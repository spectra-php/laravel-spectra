<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Dashboards;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Spectra\Actions\Dashboards\Sections\GetDashboardCallMessages;
use Spectra\Data\Responses\DashboardCallMessagesResponse;
use Spectra\Enums\DashboardSection;
use Spectra\Models\SpectraDashboard;
use Spectra\Support\Dashboards\DashboardAccess;
use Spectra\Support\Dashboards\DashboardViewer;

/**
 * GET /dashboards/{id}/data/requests/{requestId}: what one of the viewer's calls said, on a dashboard that shows messages. Not behind `viewSpectra`, because its
 * viewers aren't dashboard users. The scope comes only from the saved dashboard
 * and the logged-in viewer, never from the request.
 */
class DashboardCallMessagesController extends Controller
{
    /**
     * Get the call's message and reply.
     */
    public function __invoke(string $id, string $requestId, Request $request, DashboardViewer $viewer, GetDashboardCallMessages $getMessages): DashboardCallMessagesResponse
    {
        abort_unless(DashboardAccess::enabled(), 404);

        $dashboard = SpectraDashboard::query()->where('is_active', true)->findOrFail($id);
        $scope = $viewer->scope($dashboard, $request->user());

        abort_if($scope === null, 403);
        abort_unless($dashboard->hasSection(DashboardSection::Requests), 404);

        abort_unless($dashboard->show_messages, 404);

        $messages = $getMessages($scope, $requestId);

        abort_if($messages === null, 404);

        return $messages;
    }
}
