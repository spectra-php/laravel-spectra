<?php

declare(strict_types=1);

namespace Spectra\Actions\Trackables;

use Spectra\Data\TrackableStats as TrackableStatsDto;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * The headline numbers on a trackable's detail screen: counts and usage over
 * every request, cost and latency over successful ones.
 */
class GetTrackableStats
{
    public function __invoke(string $type, string $id, DateRange $dateRange): TrackableStatsDto
    {
        $stats = RequestAggregateQuery::make($dateRange)
            ->forTrackable($type, $id)
            ->countAs('total_requests')
            ->tokenSum()
            ->usageSums()
            ->audioSums()
            ->successfulCost()
            ->successfulLatency('latency_avg')
            ->first();

        return new TrackableStatsDto(
            total_requests: $stats->total_requests ?? 0,
            total_tokens: $stats->tokens_sum ?? 0,
            total_images: $stats->images_sum ?? 0,
            total_videos: $stats->videos_sum ?? 0,
            total_tts_characters: $stats->tts_characters_sum ?? 0,
            tts_duration_seconds: $stats->tts_duration_sum ?? 0,
            stt_duration_seconds: $stats->stt_duration_sum ?? 0,
            total_cost: $stats->cost ?? 0,
            avg_latency: $stats->latency_avg ?? 0,
        );
    }
}
