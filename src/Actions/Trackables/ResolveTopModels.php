<?php

namespace Spectra\Actions\Trackables;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;

class ResolveTopModels
{
    /**
     * @param  \Illuminate\Support\Collection<int, SpectraRequest>  $pageItems
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
            ->unique(fn ($pair) => $pair['trackable_type'].':'.$pair['trackable_id'])
            ->values();

        $modelCounts = SpectraRequest::query()
            ->select('trackable_type', 'trackable_id', 'model')
            ->selectRaw('COUNT(*) as model_count')
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
            $key = $row->trackable_type.':'.$row->trackable_id;
            if (! array_key_exists($key, $topModels)) {
                $topModels[$key] = $row->model;
            }
        }

        return $topModels;
    }
}
