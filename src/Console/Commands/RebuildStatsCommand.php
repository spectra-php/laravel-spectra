<?php

declare(strict_types=1);

namespace Spectra\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Spectra\Actions\Maintenance\RebuildStats;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Recomputes the daily stats table from the stored requests.
 *
 * The dashboard reads the daily stats, which are updated as requests are
 * saved; rebuild them after importing, deleting or repricing requests.
 */
#[AsCommand(name: 'spectra:rebuild-stats')]
class RebuildStatsCommand extends Command
{
    protected $signature = 'spectra:rebuild-stats
                            {--from= : Start date (Y-m-d)}
                            {--to= : End date (Y-m-d)}
                            {--yesterday : Rebuild only yesterday stats}';

    protected $description = 'Rebuild aggregated statistics from request data';

    /**
     * Rebuild the daily stats for yesterday, a date range or all stored requests.
     */
    public function handle(RebuildStats $rebuild): int
    {
        if ($this->option('yesterday')) {
            $this->info('Rebuilding stats for yesterday...');
            $yesterday = now()->subDay();
            $count = $rebuild($yesterday->copy()->startOfDay(), $yesterday->copy()->endOfDay());
            $this->info("Processed {$count} requests.");

            return self::SUCCESS;
        }

        $from = $this->option('from')
            ? Date::parse($this->option('from'))
            : null;

        $to = $this->option('to')
            ? Date::parse($this->option('to'))
            : null;

        if ($from && $to && $from->gt($to)) {
            $this->error("The --from date ({$from->format('Y-m-d')}) must be before --to ({$to->format('Y-m-d')}).");

            return self::FAILURE;
        }

        if ($from && $to) {
            $this->info("Rebuilding stats from {$from->format('Y-m-d')} to {$to->format('Y-m-d')}...");
        } elseif ($from) {
            $this->info("Rebuilding stats from {$from->format('Y-m-d')} to now...");
        } elseif ($to) {
            $this->info("Rebuilding stats up to {$to->format('Y-m-d')}...");
        } else {
            if (! $this->confirm('This will rebuild ALL stats. Continue?', false)) {
                return self::FAILURE;
            }
            $this->info('Rebuilding all stats...');
        }

        $count = $rebuild($from, $to);

        $this->info("Successfully processed {$count} requests.");

        return self::SUCCESS;
    }
}
