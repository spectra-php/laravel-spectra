<?php

declare(strict_types=1);

namespace Spectra\Actions\Trackables;

use Illuminate\Http\Request;
use Spectra\Data\Responses\TrackablesSummaryResponse;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * The totals strip above the trackables list: how many trackables match the
 * screen's filters and their combined usage, cost and latency.
 */
class GetTrackablesSummary
{
    /**
     * Get the trackable count and combined usage, cost and latency for the filters.
     */
    public function __invoke(Request $request, DateRange $dateRange): TrackablesSummaryResponse
    {
        $summary = RequestAggregateQuery::forTrackableFilters($dateRange, $request)
            ->withTrackable()
            ->filteredBy($request)
            ->countAs('total_requests')
            ->tokenSum()
            ->usageSums()
            ->audioSums()
            ->successfulCost()
            ->successfulLatency()
            ->first();

        $totalTrackables = RequestAggregateQuery::forTrackableFilters($dateRange, $request)
            ->withTrackable()
            ->filteredBy($request)
            ->countDistinct('trackable_type', 'trackable_id');

        return new TrackablesSummaryResponse(
            total_trackables: $totalTrackables,
            total_requests: $summary->total_requests ?? 0,
            total_tokens: $summary->tokens_sum ?? 0,
            total_images: $summary->images_sum ?? 0,
            total_videos: $summary->videos_sum ?? 0,
            total_tts_characters: $summary->tts_characters_sum ?? 0,
            tts_duration_seconds: $summary->tts_duration_sum ?? 0,
            stt_duration_seconds: $summary->stt_duration_sum ?? 0,
            total_cost: $summary->cost ?? 0,
            avg_latency: $summary->avg_latency ?? 0,
        );
    }
}
