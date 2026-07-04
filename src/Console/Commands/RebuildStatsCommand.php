<?php

declare(strict_types=1);

namespace Spectra\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Spectra\Support\StatsAggregator;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'spectra:rebuild-stats')]
class RebuildStatsCommand extends Command
{
    protected $signature = 'spectra:rebuild-stats
                            {--from= : Start date (Y-m-d)}
                            {--to= : End date (Y-m-d)}
                            {--yesterday : Rebuild only yesterday stats}';

    protected $description = 'Rebuild aggregated statistics from request data';

    public function handle(StatsAggregator $aggregator): int
    {
        if ($this->option('yesterday')) {
            $this->info('Rebuilding stats for yesterday...');
            $count = $aggregator->rebuildYesterday();
            $this->info("Processed {$count} requests.");

            return self::SUCCESS;
        }

        $from = $this->option('from')
            ? Carbon::parse($this->option('from'))
            : null;

        $to = $this->option('to')
            ? Carbon::parse($this->option('to'))
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

        $count = $aggregator->rebuild($from, $to);

        $this->info("Successfully processed {$count} requests.");

        return self::SUCCESS;
    }
}
