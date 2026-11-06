<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Maintenance;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spectra\Actions\Maintenance\QueueMaintenanceJob;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Jobs\PruneEntriesJob;
use Spectra\Support\Maintenance\MaintenanceAccess;

/**
 * POST /api/maintenance/prune: queue a prune that keeps the last `hours` of
 * requests and spans, and deletes daily stats older than `stats_days` when given.
 */
class PruneController extends BaseApiController
{
    /**
     * Queue the prune and answer 202 Accepted.
     */
    public function __invoke(Request $request, QueueMaintenanceJob $queue): JsonResponse
    {
        abort_unless(MaintenanceAccess::allowed(), 403);

        $validated = $request->validate([
            'hours' => ['required', 'integer', 'min:1', 'max:876000'],
            'stats_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
        ]);

        $queue(new PruneEntriesJob(
            hours: (int) $validated['hours'],
            statsDays: isset($validated['stats_days']) ? (int) $validated['stats_days'] : null,
        ));

        return new JsonResponse(['queued' => true], 202);
    }
}
