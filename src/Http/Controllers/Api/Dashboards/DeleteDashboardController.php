<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Dashboards;

use Illuminate\Http\JsonResponse;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Models\SpectraDashboard;
use Spectra\Support\Dashboards\DashboardAccess;

/**
 * DELETE /api/dashboards/{id}: delete a shared dashboard, which ends
 * its URL. The calls it showed stay stored.
 */
class DeleteDashboardController extends BaseApiController
{
    /**
     * Delete the dashboard.
     */
    public function __invoke(string $id): JsonResponse
    {
        abort_unless(DashboardAccess::allowed(), 403);

        SpectraDashboard::query()->findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }
}
