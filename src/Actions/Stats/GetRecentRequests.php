<?php

namespace Spectra\Actions\Stats;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Concerns\FiltersByLayout;
use Spectra\Support\DateRange;

class GetRecentRequests
{
    use FiltersByLayout;

    /**
     * @return \Illuminate\Support\Collection<int, SpectraRequest>
     */
    public function __invoke(DateRange $dateRange, string $layout, int $limit = 10): Collection
    {
        $query = SpectraRequest::query()->latest('created_at');
        $dateRange->apply($query);
        $this->applyLayoutFilter($query, $layout);

        return $query
            ->limit($limit)
            ->get(['id', 'provider', 'model', 'model_type', 'status_code', 'prompt_tokens', 'completion_tokens', 'image_count', 'video_count', 'duration_seconds', 'input_characters', 'created_at']);
    }
}
