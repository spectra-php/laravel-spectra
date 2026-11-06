<?php

declare(strict_types=1);

namespace Spectra\Actions\Dashboards;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Spectra\Enums\ScoreType;
use Spectra\Models\SpectraScore;
use Spectra\Support\Dashboards\DashboardScope;
use Spectra\Support\DateRange;
use stdClass;

/**
 * The scores on the trackable's calls, traces and conversations on a shared
 * dashboard, by name. A score's comment and author are never read. Empty until
 * the scores migration runs.
 */
class GetDashboardScores
{
    /** Labels listed per categorical score. */
    private const TOP_LABELS = 3;

    /** Score names listed. */
    private const LIMIT = 20;

    /**
     * Get one row per score name and type, most given first.
     *
     * @return Collection<int, mixed>
     */
    public function __invoke(DashboardScope $scope, DateRange $dateRange): Collection
    {
        return rescue(fn () => $this->summarize($scope, $dateRange), new Collection, report: false);
    }

    /**
     * Aggregate the period's scores by name and type, with the top labels of categorical ones.
     *
     * @return Collection<int, mixed>
     */
    private function summarize(DashboardScope $scope, DateRange $dateRange): Collection
    {
        $calls = fn (string $column): Builder => $scope->calls()->whereNotNull($column)->select($column);

        $query = SpectraScore::query()->where(fn (Builder $scores) => $scores
            ->whereIn('request_id', $calls('id'))
            ->orWhereIn('trace_id', $calls('trace_id'))
            ->orWhereIn('conversation_id', $calls('conversation_id')));

        $dateRange->apply($query);

        $labels = $query->clone()->toBase()
            ->where('type', ScoreType::Categorical->value)
            ->select('name', 'label')
            ->selectRaw('COUNT(*) as label_count')
            ->groupBy('name', 'label')
            ->orderByDesc('label_count')
            ->get()
            ->groupBy('name');

        return $query->clone()->toBase()
            ->select('name', 'type')
            ->selectRaw('COUNT(*) as score_count, AVG(value) as average, MIN(value) as minimum, MAX(value) as maximum')
            ->groupBy('name', 'type')
            ->orderByDesc('score_count')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (stdClass $row) => [
                'name' => (string) $row->name,
                'type' => (string) $row->type,
                'count' => (int) $row->score_count,
                'average' => $row->average !== null ? round((float) $row->average, 4) : null,
                'minimum' => $row->minimum !== null ? (float) $row->minimum : null,
                'maximum' => $row->maximum !== null ? (float) $row->maximum : null,
                'labels' => $row->type === ScoreType::Categorical->value
                    ? collect($labels->get($row->name, []))
                        ->take(self::TOP_LABELS)
                        ->map(fn (stdClass $label) => ['label' => (string) $label->label, 'count' => (int) $label->label_count])
                        ->values()
                        ->all()
                    : [],
            ])
            ->values();
    }
}
