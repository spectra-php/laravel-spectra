<?php

declare(strict_types=1);

namespace Spectra\Actions\Maintenance;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spectra\Data\PrunePreview;
use Spectra\Models\SpectraAnnotationItem;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraScore;
use Spectra\Models\SpectraSpan;

/**
 * Counts what PruneEntries would delete with the same retention, without
 * deleting anything, so the dashboard can say it before it is confirmed.
 */
class PreviewPrune
{
    /** Where a preview stops counting. */
    private const COUNT_LIMIT = 1000000;

    /** How many of the requests are checked for media. */
    private const MEDIA_SAMPLE = 20000;

    /**
     * Count the requests, spans, scores and daily stats older than the retention.
     */
    public function __invoke(int $hours, ?int $statsDays = null): PrunePreview
    {
        $cutoff = now()->subHours(max(0, $hours));
        $requests = SpectraRequest::query()->where('created_at', '<', $cutoff);
        $requestCount = $this->countUpToLimit($requests);

        return new PrunePreview(
            hours: $hours,
            stats_days: $statsDays,
            cutoff: $cutoff->toIso8601String(),
            requests: $requestCount,
            requests_with_media: $this->estimateWithMedia($requests, $requestCount),
            spans: PruneEntries::spansTableExists() ? $this->countUpToLimit(SpectraSpan::query()->where('started_at', '<', $cutoff)) : 0,
            daily_stats: $statsDays === null ? 0 : SpectraDailyStat::query()
                ->where('date', '<', now()->subDays(max(0, $statsDays))->toDateString())
                ->count(),
            scores: PruneEntries::scoresTableExists() ? SpectraScore::query()->where('created_at', '<', $cutoff)->count() : 0,
            review_items: PruneEntries::reviewItemsTableExists() ? SpectraAnnotationItem::query()->where('created_at', '<', $cutoff)->count() : 0,
        );
    }

    /**
     * Count rows, stopping at a limit: a preview only has to say how much is
     * about to go, and counting millions of rows would keep the screen waiting.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $rows
     */
    private function countUpToLimit(Builder $rows): int
    {
        return $rows->getModel()->getConnection()->query()
            ->fromSub($rows->clone()->toBase()->selectRaw('1 as counted')->limit(self::COUNT_LIMIT), 'counted_rows')
            ->count();
    }

    /**
     * Count the requests with media among the oldest ones, scaled up to all of
     * them when there are more: nothing indexes the media column.
     *
     * @param  Builder<SpectraRequest>  $requests
     */
    private function estimateWithMedia(Builder $requests, int $requestCount): int
    {
        $sampled = min($requestCount, self::MEDIA_SAMPLE);

        if ($sampled === 0) {
            return 0;
        }

        $withMedia = $requests->getModel()->getConnection()->query()
            ->fromSub($requests->clone()->toBase()->select('media_storage_path')->limit($sampled), 'sampled_rows')
            ->whereNotNull('media_storage_path')
            ->count();

        return (int) round($withMedia * $requestCount / $sampled);
    }
}
