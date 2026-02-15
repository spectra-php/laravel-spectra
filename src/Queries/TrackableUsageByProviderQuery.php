<?php

namespace Spectra\Queries;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;

class TrackableUsageByProviderQuery
{
    /**
     * @return \Illuminate\Support\Collection<int, SpectraRequest>
     */
    public function __invoke(string $type, string $id, DateRange $dateRange): Collection
    {
        $query = SpectraRequest::query()
            ->where('trackable_type', $type)
            ->where('trackable_id', $id);

        $dateRange->apply($query);

        return $query
            ->select('provider')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('SUM(prompt_tokens + completion_tokens) as tokens_sum')
            ->selectRaw('COALESCE(SUM(image_count), 0) as images_sum')
            ->selectRaw('COALESCE(SUM(video_count), 0) as videos_sum')
            ->selectRaw('COALESCE(SUM(CASE WHEN model_type = \'tts\' THEN input_characters ELSE 0 END), 0) as tts_characters_sum')
            ->selectRaw('COALESCE(SUM(CASE WHEN model_type IN (\'tts\', \'stt\') THEN duration_seconds ELSE 0 END), 0) as audio_duration_sum')
            ->selectRaw('SUM(total_cost_in_cents) as cost')
            ->groupBy('provider')
            ->orderByDesc('cost')
            ->get();
    }
}
