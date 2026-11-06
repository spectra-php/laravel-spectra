<?php

declare(strict_types=1);

namespace Spectra\Actions\Trackables;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Query\RollupSchema;

/**
 * The most used model of each trackable on one page of results, in one query
 * for the whole page.
 */
class ResolveTopModels
{
    /**
     * Create a new resolve top models action instance.
     */
    public function __construct(
        private readonly RollupSchema $rollups,
    ) {}

    /**
     * Get the most used model of each trackable on the page, keyed by type and id.
     *
     * @param  Collection<int, SpectraRequest>  $pageItems
     * @return array<string, string>
     */
    public function __invoke(Collection $pageItems): array
    {
        if ($pageItems->isEmpty()) {
            return [];
        }

        $pairs = $pageItems
            ->map(fn ($item) => [
                'trackable_type' => $item->trackable_type,
                'trackable_id' => (string) $item->trackable_id,
            ])
            ->unique(fn ($pair) => "{$pair['trackable_type']}:{$pair['trackable_id']}")
            ->values();

        // The daily stats add up the same numbers, without reading every call.
        $onRollups = $this->rollups->isReadable();
        $source = $onRollups ? SpectraRequest::query()->from('spectra_daily_stats') : SpectraRequest::query();

        $modelCounts = $source
            ->select('trackable_type', 'trackable_id', 'model')
            ->selectRaw($onRollups ? 'SUM(request_count) as model_count' : 'COUNT(*) as model_count')
            ->where(function ($query) use ($pairs) {
                foreach ($pairs as $pair) {
                    $query->orWhere(function ($inner) use ($pair) {
                        $inner->where('trackable_type', $pair['trackable_type'])
                            ->where('trackable_id', $pair['trackable_id']);
                    });
                }
            })
            ->groupBy('trackable_type', 'trackable_id', 'model')
            ->orderByDesc('model_count')
            ->get();

        $topModels = [];
        foreach ($modelCounts as $row) {
            $key = "{$row->trackable_type}:{$row->trackable_id}";
            $topModels[$key] ??= $row->model;
        }

        return $topModels;
    }
}
