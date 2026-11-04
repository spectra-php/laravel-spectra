<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Maintenance;

use Illuminate\Http\JsonResponse;
use Spectra\Actions\Maintenance\QueueMaintenanceJob;
use Spectra\Jobs\RebuildStatsJob;

/**
 * POST /api/maintenance/rebuild-stats: queue a rebuild of the daily stats of
 * the days whose requests are still stored. Pruned days keep their stats.
 */
class RebuildStatsController extends BaseMaintenanceController
{
    /**
     * Queue the rebuild and answer 202 Accepted.
     */
    public function __invoke(QueueMaintenanceJob $queue): JsonResponse
    {
        $queue(new RebuildStatsJob);

        return new JsonResponse(['queued' => true], 202);
    }
}
