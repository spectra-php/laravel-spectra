<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Maintenance;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spectra\Actions\Maintenance\QueueMaintenanceJob;
use Spectra\Jobs\PruneEntriesJob;

/**
 * POST /api/maintenance/prune: queue a prune that keeps the last `hours` of
 * requests and spans, and deletes daily stats older than `stats_days` when given.
 */
class PruneController extends BaseMaintenanceController
{
    /**
     * Queue the prune and answer 202 Accepted.
     */
    public function __invoke(Request $request, QueueMaintenanceJob $queue): JsonResponse
    {
        $validated = $request->validate(static::retentionRules());

        $queue(new PruneEntriesJob(
            hours: (int) $validated['hours'],
            statsDays: isset($validated['stats_days']) ? (int) $validated['stats_days'] : null,
        ));

        return new JsonResponse(['queued' => true], 202);
    }
}
