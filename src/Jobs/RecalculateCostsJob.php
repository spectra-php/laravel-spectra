<?php

declare(strict_types=1);

namespace Spectra\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Spectra\Actions\Maintenance\RecalculateCosts;
use Spectra\Support\Maintenance\MaintenanceHistory;
use Throwable;

/**
 * Prices the stored requests again from the pricing catalog, off the HTTP
 * request, as started from the dashboard's Prices card when Spectra's queue
 * is on. It writes the database only. Only one is queued at a time.
 */
class RecalculateCostsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * How long, in seconds, a second recalculation can't be queued while this one is.
     */
    public int $uniqueFor = 3600;

    /**
     * Recalculate the stored requests' costs.
     */
    public function handle(RecalculateCosts $recalculate): void
    {
        $recalculate();
    }

    /**
     * Stop the dashboard showing the recalculation as queued once it failed.
     */
    public function failed(?Throwable $exception): void
    {
        MaintenanceHistory::clearPending(MaintenanceHistory::RECALCULATE_COSTS);
    }
}
