<?php

namespace Spectra\Console\Commands;

use Illuminate\Console\Command;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'spectra:prune')]
class PruneCommand extends Command
{
    protected $signature = 'spectra:prune {--hours=24 : The number of hours to retain Spectra data}';

    protected $description = 'Prune stale entries from the Spectra database';

    public function handle(): void
    {
        $hours = (int) $this->option('hours');
        $cutoff = now()->subHours($hours);

        $requestCount = SpectraRequest::query()
            ->where('created_at', '<', $cutoff)
            ->delete();

        $statsCount = SpectraDailyStat::query()
            ->where('date', '<', $cutoff->toDateString())
            ->delete();

        $this->info("{$requestCount} requests and {$statsCount} daily stats pruned.");
    }
}
