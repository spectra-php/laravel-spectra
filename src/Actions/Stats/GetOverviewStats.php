<?php

declare(strict_types=1);

namespace Spectra\Actions\Stats;

use Spectra\Data\Responses\OverviewStatsResponse;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * The overview's headline totals: counts and usage over every request, cost
 * and latency over successful ones.
 */
class GetOverviewStats
{
    /**
     * Get the overview's request, usage, cost and latency totals for the date range.
     */
    public function __invoke(DateRange $dateRange, string $layout): OverviewStatsResponse
    {
        $stats = RequestAggregateQuery::fromRollups($dateRange)
            ->forLayout($layout)
            ->countAs('total_requests')
            ->tokenSum()
            ->successfulCost()
            ->successfulLatency('latency_avg')
            ->usageSums()
            ->audioSums()
            ->first();

        return new OverviewStatsResponse(
            total_requests: $stats->total_requests ?? 0,
            total_tokens: $stats->tokens_sum ?? 0,
            total_cost_in_cents: $stats->cost ?? 0,
            avg_latency: $stats->latency_avg ?? 0,
            total_images: $stats->images_sum ?? 0,
            total_videos: $stats->videos_sum ?? 0,
            total_duration_seconds: $stats->duration_seconds_sum ?? 0,
            total_input_characters: $stats->input_characters_sum ?? 0,
            tts_characters: $stats->tts_characters_sum ?? 0,
            tts_duration_seconds: $stats->tts_duration_sum ?? 0,
            stt_duration_seconds: $stats->stt_duration_sum ?? 0,
        );
    }
}
