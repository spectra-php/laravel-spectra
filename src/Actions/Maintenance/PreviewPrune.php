<?php

declare(strict_types=1);

namespace Spectra\Actions\Maintenance;

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
    /**
     * Count the requests, spans, scores and daily stats older than the retention.
     */
    public function __invoke(int $hours, ?int $statsDays = null): PrunePreview
    {
        $cutoff = now()->subHours(max(0, $hours));
        $requests = SpectraRequest::query()->where('created_at', '<', $cutoff);

        return new PrunePreview(
            hours: $hours,
            stats_days: $statsDays,
            cutoff: $cutoff->toIso8601String(),
            requests: (clone $requests)->count(),
            requests_with_media: (clone $requests)->whereNotNull('media_storage_path')->count(),
            spans: PruneEntries::spansTableExists() ? SpectraSpan::query()->where('started_at', '<', $cutoff)->count() : 0,
            daily_stats: $statsDays === null ? 0 : SpectraDailyStat::query()
                ->where('date', '<', now()->subDays(max(0, $statsDays))->toDateString())
                ->count(),
            scores: PruneEntries::scoresTableExists() ? SpectraScore::query()->where('created_at', '<', $cutoff)->count() : 0,
            review_items: PruneEntries::reviewItemsTableExists() ? SpectraAnnotationItem::query()->where('created_at', '<', $cutoff)->count() : 0,
        );
    }
}
