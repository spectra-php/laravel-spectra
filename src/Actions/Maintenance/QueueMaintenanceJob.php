<?php

declare(strict_types=1);

namespace Spectra\Actions\Maintenance;

use Spectra\Jobs\PruneEntriesJob;
use Spectra\Jobs\RebuildStatsJob;
use Spectra\Support\Maintenance\MaintenanceHistory;

/**
 * Queues a maintenance job from the dashboard on Spectra's queue connection
 * and queue, and marks its task as pending until the job records its run.
 */
class QueueMaintenanceJob
{
    /**
     * Queue the job and mark its task as pending.
     */
    public function __invoke(PruneEntriesJob|RebuildStatsJob $job): void
    {
        if ($connection = config('spectra.queue.connection')) {
            $job->onConnection($connection);
        }

        if ($queue = config('spectra.queue.queue')) {
            $job->onQueue($queue);
        }

        // Before dispatching: on the sync driver the job runs right here,
        // records its run and clears the mark again.
        MaintenanceHistory::markPending($job instanceof PruneEntriesJob ? MaintenanceHistory::PRUNE : MaintenanceHistory::REBUILD_STATS);

        dispatch($job);
    }
}
