<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Maintenance;

use Illuminate\Http\JsonResponse;
use Spectra\Actions\Maintenance\QueueMaintenanceJob;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Jobs\RebuildStatsJob;
use Spectra\Support\Maintenance\MaintenanceAccess;

/**
 * POST /api/maintenance/rebuild-stats: queue a rebuild of the daily stats of
 * the days whose requests are still stored. Pruned days keep their stats.
 */
class RebuildStatsController extends BaseApiController
{
    /**
     * Queue the rebuild and answer 202 Accepted.
     */
    public function __invoke(QueueMaintenanceJob $queue): JsonResponse
    {
        abort_unless(MaintenanceAccess::allowed(), 403);

        $queue(new RebuildStatsJob);

        return new JsonResponse(['queued' => true], 202);
    }
}
