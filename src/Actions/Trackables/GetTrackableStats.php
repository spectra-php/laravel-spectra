<?php

namespace Spectra\Actions\Trackables;

use Spectra\Data\TrackableStats as TrackableStatsDto;
use Spectra\Queries\TrackableStatsQuery;
use Spectra\Support\DateRange;

class GetTrackableStats
{
    public function __construct(
        private readonly TrackableStatsQuery $query,
    ) {}

    public function __invoke(string $type, string $id, DateRange $dateRange): TrackableStatsDto
    {
        $stats = ($this->query)($type, $id, $dateRange);

        return new TrackableStatsDto(
            total_requests: (int) ($stats->total_requests ?? 0),
            total_tokens: (int) ($stats->tokens_sum ?? 0),
            total_images: (int) ($stats->images_sum ?? 0),
            total_videos: (int) ($stats->videos_sum ?? 0),
            total_tts_characters: (int) ($stats->tts_characters_sum ?? 0),
            tts_duration_seconds: (float) ($stats->tts_duration_sum ?? 0),
            stt_duration_seconds: (float) ($stats->stt_duration_sum ?? 0),
            total_cost: (float) ($stats->cost_sum ?? 0),
            avg_latency: (float) ($stats->latency_avg ?? 0),
        );
    }
}
