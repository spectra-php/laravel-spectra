<?php

declare(strict_types=1);

namespace Spectra\Console\Commands;

use Illuminate\Console\Command;
use Spectra\Actions\Maintenance\PruneEntries;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Deletes old request rows (and the media files stored for them).
 *
 * Daily stats are the long-term history behind the dashboard charts, so they
 * are kept unless --stats-days is given.
 */
#[AsCommand(name: 'spectra:prune')]
class PruneCommand extends Command
{
    protected $signature = 'spectra:prune
        {--hours=24 : Keep requests from the last N hours}
        {--stats-days= : Also delete daily stats older than N days (kept by default)}
        {--chunk=1000 : Rows deleted per query}
        {--payload-hours= : Also delete request and response bodies older than N hours, keeping the calls}
        {--pause=0 : Milliseconds to wait between chunks, to leave room for live writes}';

    protected $description = 'Prune stale entries from the Spectra database';

    /**
     * Delete requests, spans, scores and media older than the cutoff, and old daily stats when asked.
     */
    public function handle(PruneEntries $prune): int
    {
        $statsDays = $this->option('stats-days');
        $payloadHours = $this->option('payload-hours');

        $result = $prune(
            hours: (int) $this->option('hours'),
            statsDays: filled($statsDays) ? (int) $statsDays : null,
            chunk: (int) $this->option('chunk'),
            payloadHours: filled($payloadHours) ? (int) $payloadHours : null,
            pauseMs: (int) $this->option('pause'),
        );

        $this->info("{$result->requests} requests, {$result->spans} spans, {$result->scores} scores, {$result->review_items} review items, {$result->media_files} media files and {$result->daily_stats} daily stats pruned.".($result->payloads > 0 ? " Bodies of {$result->payloads} calls deleted." : ''));

        return self::SUCCESS;
    }
}
