<?php

namespace Spectra\Actions\Stats;

use Spectra\Data\Responses\OverviewStatsResponse;
use Spectra\Queries\OverviewStatsQuery;
use Spectra\Support\DateRange;

class GetOverviewStats
{
    public function __construct(
        private readonly OverviewStatsQuery $query,
    ) {}

    public function __invoke(DateRange $dateRange, string $layout): OverviewStatsResponse
    {
        $stats = ($this->query)($dateRange, $layout);

        return new OverviewStatsResponse(
            total_requests: (int) ($stats->total_requests ?? 0),
            total_tokens: (int) ($stats->tokens_sum ?? 0),
            total_cost_in_cents: (float) ($stats->cost_sum ?? 0),
            avg_latency: (float) ($stats->latency_avg ?? 0),
            total_images: (int) ($stats->total_images ?? 0),
            total_videos: (int) ($stats->total_videos ?? 0),
            total_duration_seconds: (float) ($stats->total_duration_seconds ?? 0),
            total_input_characters: (int) ($stats->total_input_characters ?? 0),
            tts_characters: (int) ($stats->tts_characters ?? 0),
            tts_duration_seconds: (float) ($stats->tts_duration_seconds ?? 0),
            stt_duration_seconds: (float) ($stats->stt_duration_seconds ?? 0),
        );
    }
}
