<?php

declare(strict_types=1);

namespace Spectra\Actions\Scores;

use Illuminate\Support\Collection;
use Spectra\Data\ScoreSummary;
use Spectra\Enums\ScoreType;
use Spectra\Models\SpectraScore;
use Spectra\Support\DateRange;
use stdClass;

/**
 * The overview's Scores card: each score name given in the period, how often,
 * and its average (the share of yes for yes/no scores) or its most common
 * labels. Nothing until the app runs the scores migration.
 */
class GetScoreSummary
{
    /** Labels listed per categorical score. */
    private const TOP_LABELS = 3;

    /** Score names listed. */
    private const LIMIT = 20;

    /**
     * Get one summary row per score name and type, most given first.
     *
     * @return Collection<int, ScoreSummary>
     */
    public function __invoke(DateRange $dateRange): Collection
    {
        return rescue(fn () => $this->summaries($dateRange), new Collection, report: false);
    }

    /**
     * Aggregate the period's scores by name and type, with the top labels of categorical ones.
     *
     * @return Collection<int, ScoreSummary>
     */
    private function summaries(DateRange $dateRange): Collection
    {
        $query = SpectraScore::query();
        $dateRange->apply($query);

        $rows = $query->clone()
            ->toBase()
            ->select('name', 'type')
            ->selectRaw('COUNT(*) as score_count, AVG(value) as average, MIN(value) as minimum, MAX(value) as maximum')
            ->groupBy('name', 'type')
            ->orderByDesc('score_count')
            ->limit(self::LIMIT)
            ->get();

        $labels = $query->clone()
            ->toBase()
            ->where('type', ScoreType::Categorical->value)
            ->select('name', 'label')
            ->selectRaw('COUNT(*) as label_count')
            ->groupBy('name', 'label')
            ->orderByDesc('label_count')
            ->get()
            ->groupBy('name');

        return $rows->map(fn (stdClass $row) => new ScoreSummary(
            name: (string) $row->name,
            type: (string) $row->type,
            count: (int) $row->score_count,
            average: $row->average !== null ? round((float) $row->average, 4) : null,
            minimum: $row->minimum !== null ? (float) $row->minimum : null,
            maximum: $row->maximum !== null ? (float) $row->maximum : null,
            labels: $row->type === ScoreType::Categorical->value
                ? collect($labels->get($row->name, []))
                    ->take(self::TOP_LABELS)
                    ->map(fn (stdClass $label) => ['label' => (string) $label->label, 'count' => (int) $label->label_count])
                    ->values()
                    ->all()
                : [],
        ))->values();
    }
}
