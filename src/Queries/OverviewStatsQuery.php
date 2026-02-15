<?php

namespace Spectra\Queries;

use Spectra\Models\SpectraRequest;
use Spectra\Support\Concerns\FiltersByLayout;
use Spectra\Support\DateRange;

class OverviewStatsQuery
{
    use FiltersByLayout;

    public function __invoke(DateRange $dateRange, string $layout): ?SpectraRequest
    {
        $query = SpectraRequest::query();
        $dateRange->apply($query);
        $this->applyLayoutFilter($query, $layout);

        return $query
            ->selectRaw('COUNT(*) as total_requests')
            ->selectRaw('SUM(prompt_tokens + completion_tokens) as tokens_sum')
            ->selectRaw('SUM(total_cost_in_cents) as cost_sum')
            ->selectRaw('AVG(latency_ms) as latency_avg')
            ->selectRaw('COALESCE(SUM(image_count), 0) as total_images')
            ->selectRaw('COALESCE(SUM(video_count), 0) as total_videos')
            ->selectRaw('COALESCE(SUM(duration_seconds), 0) as total_duration_seconds')
            ->selectRaw('COALESCE(SUM(input_characters), 0) as total_input_characters')
            ->selectRaw("COALESCE(SUM(CASE WHEN model_type = 'tts' THEN input_characters ELSE 0 END), 0) as tts_characters")
            ->selectRaw("COALESCE(SUM(CASE WHEN model_type = 'tts' THEN duration_seconds ELSE 0 END), 0) as tts_duration_seconds")
            ->selectRaw("COALESCE(SUM(CASE WHEN model_type = 'stt' THEN duration_seconds ELSE 0 END), 0) as stt_duration_seconds")
            ->first();
    }
}
