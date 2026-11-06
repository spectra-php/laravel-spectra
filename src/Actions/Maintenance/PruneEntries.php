<?php

declare(strict_types=1);

namespace Spectra\Actions\Maintenance;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Spectra\Data\PruneResult;
use Spectra\Models\SpectraAnnotationItem;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraRequestPayload;
use Spectra\Models\SpectraScore;
use Spectra\Models\SpectraSpan;
use Spectra\Support\Maintenance\MaintenanceHistory;
use Spectra\Support\Tracking\PayloadStore;
use Throwable;

/**
 * Deletes requests, spans, scores and review queue items older than the retention, the media files
 * stored for those requests, and, only when asked, old daily stats.
 *
 * Daily stats are the long-term history behind the dashboard charts, so they
 * are kept unless a stats retention is given. Used by spectra:prune and the
 * dashboard's maintenance screen.
 */
class PruneEntries
{
    /**
     * How long to wait between chunks of this run.
     */
    protected int $pauseMs = 0;

    /**
     * Prune, record the run for the dashboard, and return what was deleted.
     *
     * @param  int  $hours  Keep requests and spans from the last N hours
     * @param  int|null  $statsDays  Also delete daily stats older than N days; null keeps them
     * @param  int  $chunk  Rows deleted per query
     * @param  int|null  $payloadHours  Also delete request and response bodies older than N hours, keeping the calls; null keeps them as long as their calls
     * @param  int  $pauseMs  Time to wait between chunks, to leave room for live writes
     */
    public function __invoke(int $hours, ?int $statsDays = null, int $chunk = 1000, ?int $payloadHours = null, int $pauseMs = 0): PruneResult
    {
        $cutoff = now()->subHours(max(0, $hours));
        $chunk = max(1, $chunk);
        $this->pauseMs = max(0, $pauseMs);

        [$requests, $mediaFiles] = $this->pruneRequests($cutoff, $chunk);
        $payloads = $payloadHours === null ? 0 : $this->prunePayloads(now()->subHours(max(0, $payloadHours)), $chunk);

        $result = new PruneResult(
            requests: $requests,
            spans: static::spansTableExists() ? $this->pruneSpans($cutoff, $chunk) : 0,
            media_files: $mediaFiles,
            daily_stats: $statsDays === null ? 0 : $this->pruneDailyStats($statsDays),
            scores: static::scoresTableExists() ? $this->pruneScores($cutoff, $chunk) : 0,
            review_items: static::reviewItemsTableExists() ? $this->pruneReviewItems($cutoff, $chunk) : 0,
            payloads: $payloads,
        );

        MaintenanceHistory::record(MaintenanceHistory::PRUNE, [
            ...$result->toArray(),
            'hours' => $hours,
            'stats_days' => $statsDays,
            'payload_hours' => $payloadHours,
        ]);

        return $result;
    }

    /**
     * Determine if the spans table exists.
     *
     * An app that updated Spectra but hasn't run the spans migration yet has
     * no spectra_spans table; pruning the rest must still work there.
     */
    public static function spansTableExists(): bool
    {
        $span = new SpectraSpan;

        return $span->getConnection()->getSchemaBuilder()->hasTable($span->getTable());
    }

    /**
     * Determine if the scores table exists.
     *
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
        $connection = (new SpectraRequest)->getConnection();
        $hasPayloads = app(PayloadStore::class)->isAvailable();

        // Oldest first through the created_at index, one short statement per
        // table, so no lock outlives its own chunk and a live write, which only
        // touches the newest rows, never waits for this.
        do {
            $requests = SpectraRequest::query()
                ->where('created_at', '<', $cutoff)->oldest()
                ->limit($chunk)
                ->get(['id', 'media_storage_path']);

            if ($requests->isEmpty()) {
                break;
            }

            $ids = $requests->modelKeys();
            $fileCount += $this->deleteMedia($requests->pluck('media_storage_path')->all());

            // Removing the links first saves the cascade a lookup per request.
            $connection->table('spectra_requests_tags')->whereIn('request_id', $ids)->delete();

            if ($hasPayloads) {
                SpectraRequestPayload::query()->whereKey($ids)->delete();
            }

            $deleted = SpectraRequest::query()->whereKey($ids)->delete();
            $requestCount += $deleted;

            $this->pause();
        } while ($deleted > 0);

        return [$requestCount, $fileCount];
    }

    /**
     * Delete the bodies of calls older than the cutoff, keeping the calls, and
     * return how many calls lost theirs.
     */
    protected function prunePayloads(CarbonInterface $cutoff, int $chunk): int
    {
        $count = 0;

        if (app(PayloadStore::class)->isAvailable()) {
            do {
                $ids = SpectraRequestPayload::query()->where('created_at', '<', $cutoff)->oldest()->limit($chunk)->pluck('request_id');
                $deleted = $ids->isEmpty() ? 0 : SpectraRequestPayload::query()->whereKey($ids->all())->delete();
                $count += $deleted;

                $this->pause();
            } while ($deleted > 0);
        }

        // Rows from before the payloads table hold their bodies themselves. The
        // walk moves on with each chunk, so it doesn't reread the emptied rows.
        $from = null;

        do {
            $rows = SpectraRequest::query()
                ->where('created_at', '<', $cutoff)
                ->when($from !== null, fn ($query) => $query->where('created_at', '>=', $from))
                ->where(fn ($query) => $query->whereNotNull('request')->orWhereNotNull('response'))->oldest()
                ->limit($chunk)
                ->toBase()
                ->get(['id', 'created_at']);

            if ($rows->isNotEmpty()) {
                $count += SpectraRequest::query()->whereKey($rows->pluck('id')->all())->update(['request' => null, 'response' => null]);
                $from = $rows->last()->created_at;

                $this->pause();
            }
        } while ($rows->count() === $chunk);

        return $count;
    }

    /**
     * Wait between chunks, when asked to.
     */
    protected function pause(): void
    {
        if ($this->pauseMs > 0) {
            Sleep::for($this->pauseMs)->milliseconds();
        }
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
     * Determine if the review queue items table exists.
     *
     * An app that hasn't run the annotation migration yet has no
     * spectra_annotation_items table; pruning the rest must still work there.
     */
    public static function reviewItemsTableExists(): bool
    {
        $item = new SpectraAnnotationItem;

        return $item->getConnection()->getSchemaBuilder()->hasTable($item->getTable());
    }

    /**
     * Delete review queue items created before the cutoff, whose traces are
     * pruned with them, and return how many were deleted.
     */
    protected function pruneReviewItems(CarbonInterface $cutoff, int $chunk): int
    {
        $count = 0;

        do {
            $itemIds = SpectraAnnotationItem::query()->where('created_at', '<', $cutoff)->limit($chunk)->pluck('id');
            $deleted = $itemIds->isEmpty() ? 0 : SpectraAnnotationItem::query()->whereKey($itemIds->all())->delete();
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
