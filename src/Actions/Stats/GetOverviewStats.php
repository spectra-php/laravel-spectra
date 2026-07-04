<?php

declare(strict_types=1);

namespace Spectra\Actions\Stats;

use Spectra\Data\Responses\OverviewStatsResponse;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

class GetOverviewStats
{
    public function __invoke(DateRange $dateRange, string $layout): OverviewStatsResponse
    {
        $stats = RequestAggregateQuery::make($dateRange)
            ->forLayout($layout)
            ->countAs('total_requests')
            ->tokenSum()
            ->successfulCost()
            ->successfulLatency('latency_avg')
            ->usageSums()
            ->audioSums()
            ->first();

        return new OverviewStatsResponse(
            total_requests: (int) ($stats->total_requests ?? 0),
            total_tokens: (int) ($stats->tokens_sum ?? 0),
            total_cost_in_cents: (float) ($stats->cost ?? 0),
            avg_latency: (float) ($stats->latency_avg ?? 0),
            total_images: (int) ($stats->images_sum ?? 0),
            total_videos: (int) ($stats->videos_sum ?? 0),
            total_duration_seconds: (float) ($stats->duration_seconds_sum ?? 0),
            total_input_characters: (int) ($stats->input_characters_sum ?? 0),
            tts_characters: (int) ($stats->tts_characters_sum ?? 0),
            tts_duration_seconds: (float) ($stats->tts_duration_sum ?? 0),
            stt_duration_seconds: (float) ($stats->stt_duration_sum ?? 0),
        );
    }
}
