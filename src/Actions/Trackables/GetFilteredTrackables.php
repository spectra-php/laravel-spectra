<?php

namespace Spectra\Actions\Trackables;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Concerns\FiltersTrackables;
use Spectra\Support\DateRange;

class GetFilteredTrackables
{
    use FiltersTrackables;

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, SpectraRequest>
     */
    public function __invoke(Request $request, DateRange $dateRange): LengthAwarePaginator
    {
        $query = SpectraRequest::query()
            ->whereNotNull('trackable_type')
            ->whereNotNull('trackable_id');

        $dateRange->apply($query);
        $this->applyTrackableFilters($query, $request);

        $sortBy = $request->input('sort_by', 'cost');
        $sortDir = $request->input('sort_dir', 'desc');

        $sortColumn = match ($sortBy) {
            'requests' => 'requests',
            'tokens' => 'tokens',
            'cost' => 'cost',
            default => 'cost',
        };

        return $query
            ->select('trackable_type', 'trackable_id')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('SUM(prompt_tokens + completion_tokens) as tokens_sum')
            ->selectRaw('COALESCE(SUM(image_count), 0) as images_sum')
            ->selectRaw('COALESCE(SUM(video_count), 0) as videos_sum')
            ->selectRaw('COALESCE(SUM(CASE WHEN model_type = \'tts\' THEN input_characters ELSE 0 END), 0) as tts_characters_sum')
            ->selectRaw('COALESCE(SUM(CASE WHEN model_type IN (\'tts\', \'stt\') THEN duration_seconds ELSE 0 END), 0) as audio_duration_sum')
            ->selectRaw('SUM(total_cost_in_cents) as cost')
            ->selectRaw('AVG(latency_ms) as latency_avg')
            ->groupBy('trackable_type', 'trackable_id')
            ->orderBy($sortColumn, $sortDir)
            ->paginate($request->input('per_page', 25));
    }
}
