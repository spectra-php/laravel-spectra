<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Dashboards;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Spectra\Actions\Dashboards\Sections\GetDashboardConversation;
use Spectra\Data\Responses\DashboardConversationResponse;
use Spectra\Enums\DashboardSection;
use Spectra\Models\SpectraDashboard;
use Spectra\Support\Dashboards\DashboardAccess;
use Spectra\Support\Dashboards\DashboardViewer;

/**
 * GET /dashboards/{id}/data/conversations/{conversationId}: one of the viewer's conversations. Not behind `viewSpectra`, because its
 * viewers aren't dashboard users. The scope comes only from the saved dashboard
 * and the logged-in viewer, never from the request.
 */
class DashboardConversationController extends Controller
{
    /**
     * Get the conversation when every call in it is the viewer's.
     */
    public function __invoke(string $id, string $conversationId, Request $request, DashboardViewer $viewer, GetDashboardConversation $getConversation): DashboardConversationResponse
    {
        abort_unless(DashboardAccess::enabled(), 404);

        $dashboard = SpectraDashboard::query()->where('is_active', true)->findOrFail($id);
        $scope = $viewer->scope($dashboard, $request->user());

        abort_if($scope === null, 403);
        abort_unless($dashboard->hasSection(DashboardSection::Conversations), 404);

        $conversation = $getConversation($scope, $conversationId, $dashboard->show_costs, $dashboard->show_messages);

        abort_if($conversation === null, 404);

        return $conversation;
    }
}
