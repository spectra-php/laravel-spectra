<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Maintenance;

use Illuminate\Http\JsonResponse;
use Spectra\Actions\Maintenance\QueueMaintenanceJob;
use Spectra\Actions\Maintenance\RecalculateCosts;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Jobs\RecalculateCostsJob;
use Spectra\Support\Maintenance\MaintenanceAccess;

/**
 * POST /api/maintenance/pricing/recalculate: price the stored requests again
 * from the pricing catalog. Needs the `manageSpectra` gate.
 *
 * It runs where Spectra's own writes run: as a queued job when
 * `spectra.queue.enabled` is on, otherwise now, during the request.
 */
class RecalculateCostsController extends BaseApiController
{
    /**
     * Queue the recalculation and answer 202 Accepted, or run it and answer with what it changed.
     */
    public function __invoke(QueueMaintenanceJob $queue, RecalculateCosts $recalculate): JsonResponse
    {
        abort_unless(MaintenanceAccess::allowed(), 403);

        if (! config('spectra.queue.enabled')) {
            return new JsonResponse(['queued' => false, ...$recalculate()]);
        }

        $queue(new RecalculateCostsJob);

        return new JsonResponse(['queued' => true], 202);
    }
}
