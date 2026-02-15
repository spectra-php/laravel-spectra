<?php

namespace Spectra\Queries;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;

class CostsByModelTypeQuery
{
    /**
     * @return \Illuminate\Support\Collection<int, SpectraRequest>
     */
    public function __invoke(DateRange $dateRange): Collection
    {
        $query = SpectraRequest::query()
            ->whereBetween('status_code', [200, 299]);

        $dateRange->apply($query);

        return $query
            ->select('model_type')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('COALESCE(SUM(prompt_tokens + completion_tokens), 0) as tokens_sum')
            ->selectRaw('COALESCE(SUM(image_count), 0) as images_sum')
            ->selectRaw('COALESCE(SUM(video_count), 0) as videos_sum')
            ->selectRaw('COALESCE(SUM(input_characters), 0) as input_characters_sum')
            ->selectRaw('COALESCE(SUM(duration_seconds), 0) as duration_seconds_sum')
            ->selectRaw('SUM(total_cost_in_cents) as cost_sum')
            ->groupBy('model_type')
            ->orderByDesc('cost_sum')
            ->get();
    }
}
