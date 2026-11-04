<?php

declare(strict_types=1);

namespace Spectra\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Spectra\Actions\Maintenance\RebuildStats;
use Spectra\Support\Maintenance\MaintenanceHistory;
use Throwable;

/**
 * Rebuilds the daily stats of the days whose requests are still stored, off
 * the HTTP request, as started from the dashboard. Pruned days keep their
 * stats. Only one is queued at a time.
 */
class RebuildStatsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * How long, in seconds, a second rebuild can't be queued while this one is.
     */
    public int $uniqueFor = 3600;

    /**
     * Rebuild the daily stats of the stored days.
     */
    public function handle(RebuildStats $rebuild): void
    {
        $rebuild->storedDays();
    }

    /**
     * Stop the dashboard showing the rebuild as queued once it failed.
     */
    public function failed(?Throwable $exception): void
    {
        MaintenanceHistory::clearPending(MaintenanceHistory::REBUILD_STATS);
    }
}
