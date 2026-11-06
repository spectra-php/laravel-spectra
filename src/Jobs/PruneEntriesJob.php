<?php

declare(strict_types=1);

namespace Spectra\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Spectra\Actions\Maintenance\PruneEntries;
use Spectra\Support\Maintenance\MaintenanceHistory;
use Throwable;

/**
 * Runs a prune started from the dashboard off the HTTP request, so a large
 * table can't hit the request timeout. Only one is queued at a time.
 */
class PruneEntriesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * How long, in seconds, a second prune can't be queued while this one is.
     */
    public int $uniqueFor = 3600;

    /**
     * Create a new prune entries job instance.
     */
    public function __construct(
        public readonly int $hours,
        public readonly ?int $statsDays = null,
    ) {}

    /**
     * Prune with the retention the dashboard asked for.
     */
    public function handle(PruneEntries $prune): void
    {
        $prune($this->hours, $this->statsDays);
    }

    /**
     * Stop the dashboard showing the prune as queued once it failed.
     */
    public function failed(?Throwable $exception): void
    {
        MaintenanceHistory::clearPending(MaintenanceHistory::PRUNE);
    }
}
