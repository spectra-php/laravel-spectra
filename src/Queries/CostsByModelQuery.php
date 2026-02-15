<?php

namespace Spectra\Queries;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;

class CostsByModelQuery
{
    /**
     * @return \Illuminate\Support\Collection<int, SpectraRequest>
     */
    public function __invoke(DateRange $dateRange, int $limit = 10): Collection
    {
        $query = SpectraRequest::query()
            ->whereBetween('status_code', [200, 299]);

        $dateRange->apply($query);

        return $query
            ->select('model', 'provider', 'model_type')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('COALESCE(SUM(prompt_tokens + completion_tokens), 0) as tokens_sum')
            ->selectRaw('COALESCE(SUM(image_count), 0) as images_sum')
            ->selectRaw('COALESCE(SUM(video_count), 0) as videos_sum')
            ->selectRaw('COALESCE(SUM(input_characters), 0) as input_characters_sum')
            ->selectRaw('COALESCE(SUM(duration_seconds), 0) as duration_seconds_sum')
            ->selectRaw('SUM(total_cost_in_cents) as cost')
            ->groupBy('model', 'provider', 'model_type')
            ->orderByDesc('cost')
            ->limit($limit)
            ->get();
    }
}
