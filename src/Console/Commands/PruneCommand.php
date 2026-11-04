<?php

declare(strict_types=1);

namespace Spectra\Console\Commands;

use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraSpan;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

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
        {--chunk=1000 : Rows deleted per query}';

    protected $description = 'Prune stale entries from the Spectra database';

    public function handle(): int
    {
        $cutoff = now()->subHours(max(0, (int) $this->option('hours')));
        $chunk = max(1, (int) $this->option('chunk'));

        $requestCount = 0;
        $fileCount = 0;

        // Delete in chunks so a large table isn't locked by one huge query.
        do {
            $requests = SpectraRequest::query()
                ->where('created_at', '<', $cutoff)
                ->limit($chunk)
                ->get(['id', 'media_storage_path']);

            $fileCount += $this->deleteMedia($requests->pluck('media_storage_path')->all());

            $deleted = $requests->isEmpty()
                ? 0
                : SpectraRequest::query()->whereKey($requests->modelKeys())->delete();

            $requestCount += $deleted;
        } while ($deleted > 0);

        $spanCount = $this->spansTableExists() ? $this->pruneSpans($cutoff, $chunk) : 0;

        $statsCount = 0;
        $statsDays = $this->option('stats-days');

        if (filled($statsDays)) {
            $statsCount = SpectraDailyStat::query()
                ->where('date', '<', now()->subDays(max(0, (int) $statsDays))->toDateString())
                ->delete();
        }

        $this->info("{$requestCount} requests, {$spanCount} spans, {$fileCount} media files and {$statsCount} daily stats pruned.");

        return self::SUCCESS;
    }

    protected function pruneSpans(CarbonInterface $cutoff, int $chunk): int
    {
        $count = 0;

        do {
            $spanIds = SpectraSpan::query()->where('started_at', '<', $cutoff)->limit($chunk)->pluck('id');
            $deleted = $spanIds->isEmpty() ? 0 : SpectraSpan::query()->whereKey($spanIds->all())->delete();
            $count += $deleted;
        } while ($deleted > 0);

        return $count;
    }

    /**
     * An app that updated Spectra but hasn't run the spans migration yet has
     * no spectra_spans table; pruning the rest must still work there.
     */
    protected function spansTableExists(): bool
    {
        $span = new SpectraSpan;

        return $span->getConnection()->getSchemaBuilder()->hasTable($span->getTable());
    }

    /**
     * @param  array<int, mixed>  $paths  media_storage_path values (lists of disk paths)
     */
    protected function deleteMedia(array $paths): int
    {
        $files = collect($paths)
            ->filter(fn ($value) => is_array($value))
            ->flatten()
            ->filter(fn ($path) => filled($path))
            ->values()
            ->all();

        if ($files === []) {
            return 0;
        }

        try {
            Storage::disk(config('spectra.storage.media.disk'))->delete($files);
        } catch (Throwable $e) {
            report($e);

            return 0;
        }

        return count($files);
    }
}
