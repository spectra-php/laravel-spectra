<?php

namespace Spectra\Actions\Trackables;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spectra\Data\Responses\TrackablesSummaryResponse;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Concerns\FiltersTrackables;
use Spectra\Support\DateRange;

class GetTrackablesSummary
{
    use FiltersTrackables;

    public function __invoke(Request $request, DateRange $dateRange): TrackablesSummaryResponse
    {
        $query = SpectraRequest::query()
            ->whereNotNull('trackable_type')
            ->whereNotNull('trackable_id');

        $dateRange->apply($query);
        $this->applyTrackableFilters($query, $request);

        $concatDistinct = $this->concatDistinctExpression();

        $summary = $query
            ->selectRaw("{$concatDistinct} as total_trackables")
            ->selectRaw('COUNT(*) as total_requests')
            ->selectRaw('SUM(prompt_tokens + completion_tokens) as tokens_sum')
            ->selectRaw('COALESCE(SUM(image_count), 0) as images_sum')
            ->selectRaw('COALESCE(SUM(video_count), 0) as videos_sum')
            ->selectRaw('COALESCE(SUM(CASE WHEN model_type = \'tts\' THEN input_characters ELSE 0 END), 0) as tts_characters_sum')
            ->selectRaw('COALESCE(SUM(CASE WHEN model_type = \'tts\' THEN duration_seconds ELSE 0 END), 0) as tts_duration_sum')
            ->selectRaw('COALESCE(SUM(CASE WHEN model_type = \'stt\' THEN duration_seconds ELSE 0 END), 0) as stt_duration_sum')
            ->selectRaw('SUM(total_cost_in_cents) as cost_sum')
            ->selectRaw('COALESCE(AVG(CASE WHEN status_code BETWEEN 200 AND 299 THEN latency_ms END), 0) as avg_latency')
            ->first();

        return new TrackablesSummaryResponse(
            total_trackables: (int) ($summary->total_trackables ?? 0),
            total_requests: (int) ($summary->total_requests ?? 0),
            total_tokens: (int) ($summary->tokens_sum ?? 0),
            total_images: (int) ($summary->images_sum ?? 0),
            total_videos: (int) ($summary->videos_sum ?? 0),
            total_tts_characters: (int) ($summary->tts_characters_sum ?? 0),
            tts_duration_seconds: (float) ($summary->tts_duration_sum ?? 0),
            stt_duration_seconds: (float) ($summary->stt_duration_sum ?? 0),
            total_cost: (float) ($summary->cost_sum ?? 0),
            avg_latency: (float) ($summary->avg_latency ?? 0),
        );
    }

    /**
     * Build a COUNT(DISTINCT ...) expression that concatenates trackable_type
     * and trackable_id in a way that works across SQLite, MySQL, and PostgreSQL.
     */
    private function concatDistinctExpression(): string
    {
        $driver = DB::connection(config('spectra.storage.connection'))->getDriverName();

        return match ($driver) {
            'mysql' => "COUNT(DISTINCT CONCAT(trackable_type, '-', trackable_id))",
            default => "COUNT(DISTINCT (trackable_type || '-' || trackable_id))",
        };
    }
}
