<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Maintenance;

use Illuminate\Http\Request;
use Spectra\Actions\Maintenance\GetMaintenanceStatus;
use Spectra\Data\Responses\MaintenanceStatusResponse;
use Spectra\Http\Controllers\Api\BaseApiController;

/**
 * GET /api/maintenance: the maintenance screen, with a preview of a prune
 * that keeps the last `hours` (and `stats_days` of daily stats). Anyone who
 * can view the dashboard sees it; `can_manage` says whether they may act.
 */
class MaintenanceStatusController extends BaseApiController
{
    /**
     * Get the maintenance status for the chosen retention.
     */
    public function __invoke(Request $request, GetMaintenanceStatus $getMaintenanceStatus): MaintenanceStatusResponse
    {
        $validated = $request->validate([
            'hours' => ['nullable', 'integer', 'min:1', 'max:876000'],
            'stats_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
        ]);

        return $getMaintenanceStatus(
            (int) ($validated['hours'] ?? 24),
            isset($validated['stats_days']) ? (int) $validated['stats_days'] : null,
        );
    }
}
