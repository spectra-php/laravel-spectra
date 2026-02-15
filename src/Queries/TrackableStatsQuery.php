<?php

namespace Spectra\Queries;

use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;

class TrackableStatsQuery
{
    public function __invoke(string $type, string $id, DateRange $dateRange): ?SpectraRequest
    {
        $query = SpectraRequest::query()
            ->where('trackable_type', $type)
            ->where('trackable_id', $id);

        $dateRange->apply($query);

        return $query
            ->selectRaw('COUNT(*) as total_requests')
            ->selectRaw('SUM(prompt_tokens + completion_tokens) as tokens_sum')
            ->selectRaw('COALESCE(SUM(image_count), 0) as images_sum')
            ->selectRaw('COALESCE(SUM(video_count), 0) as videos_sum')
            ->selectRaw('COALESCE(SUM(CASE WHEN model_type = \'tts\' THEN input_characters ELSE 0 END), 0) as tts_characters_sum')
            ->selectRaw('COALESCE(SUM(CASE WHEN model_type = \'tts\' THEN duration_seconds ELSE 0 END), 0) as tts_duration_sum')
            ->selectRaw('COALESCE(SUM(CASE WHEN model_type = \'stt\' THEN duration_seconds ELSE 0 END), 0) as stt_duration_sum')
            ->selectRaw('SUM(total_cost_in_cents) as cost_sum')
            ->selectRaw('AVG(latency_ms) as latency_avg')
            ->first();
    }
}
