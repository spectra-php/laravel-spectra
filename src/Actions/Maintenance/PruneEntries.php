<?php

declare(strict_types=1);

namespace Spectra\Actions\Maintenance;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;
use Spectra\Data\PruneResult;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraScore;
use Spectra\Models\SpectraSpan;
use Spectra\Support\Maintenance\MaintenanceHistory;
use Throwable;

/**
 * Deletes requests, spans and scores older than the retention, the media files
 * stored for those requests, and, only when asked, old daily stats.
 *
 * Daily stats are the long-term history behind the dashboard charts, so they
 * are kept unless a stats retention is given. Used by spectra:prune and the
 * dashboard's maintenance screen.
 */
class PruneEntries
{
    /**
     * Prune, record the run for the dashboard, and return what was deleted.
     *
     * @param  int  $hours  Keep requests and spans from the last N hours
     * @param  int|null  $statsDays  Also delete daily stats older than N days; null keeps them
     * @param  int  $chunk  Rows deleted per query
     */
    public function __invoke(int $hours, ?int $statsDays = null, int $chunk = 1000): PruneResult
    {
        $cutoff = now()->subHours(max(0, $hours));
        $chunk = max(1, $chunk);

        [$requests, $mediaFiles] = $this->pruneRequests($cutoff, $chunk);

        $result = new PruneResult(
            requests: $requests,
            spans: static::spansTableExists() ? $this->pruneSpans($cutoff, $chunk) : 0,
            media_files: $mediaFiles,
            daily_stats: $statsDays === null ? 0 : $this->pruneDailyStats($statsDays),
            scores: static::scoresTableExists() ? $this->pruneScores($cutoff, $chunk) : 0,
        );

        MaintenanceHistory::record(MaintenanceHistory::PRUNE, [
            ...$result->toArray(),
            'hours' => $hours,
            'stats_days' => $statsDays,
        ]);

        return $result;
    }

    /**
     * An app that updated Spectra but hasn't run the spans migration yet has
     * no spectra_spans table; pruning the rest must still work there.
     */
    public static function spansTableExists(): bool
    {
        $span = new SpectraSpan;

        return $span->getConnection()->getSchemaBuilder()->hasTable($span->getTable());
    }

    /**
     * An app that updated Spectra but hasn't run the scores migration yet has
     * no spectra_scores table; pruning the rest must still work there.
     */
    public static function scoresTableExists(): bool
    {
        $score = new SpectraScore;

        return $score->getConnection()->getSchemaBuilder()->hasTable($score->getTable());
    }

    /**
     * Delete requests created before the cutoff in chunks, with their media,
     * and return how many requests and files were deleted.
     *
     * @return array{int, int}
     */
    protected function pruneRequests(CarbonInterface $cutoff, int $chunk): array
    {
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

        return [$requestCount, $fileCount];
    }

    /**
     * Delete spans started before the cutoff in chunks and return how many were deleted.
     */
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
     * Delete scores created before the cutoff in chunks and return how many were deleted.
     */
    protected function pruneScores(CarbonInterface $cutoff, int $chunk): int
    {
        $count = 0;

        do {
            $scoreIds = SpectraScore::query()->where('created_at', '<', $cutoff)->limit($chunk)->pluck('id');
            $deleted = $scoreIds->isEmpty() ? 0 : SpectraScore::query()->whereKey($scoreIds->all())->delete();
            $count += $deleted;
        } while ($deleted > 0);

        return $count;
    }

    /**
     * Delete daily stats older than the given number of days and return how many were deleted.
     */
    protected function pruneDailyStats(int $days): int
    {
        return SpectraDailyStat::query()
            ->where('date', '<', now()->subDays(max(0, $days))->toDateString())
            ->delete();
    }

    /**
     * Delete the stored media files of pruned requests and return how many were deleted.
     *
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
