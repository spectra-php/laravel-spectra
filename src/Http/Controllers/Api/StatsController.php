<?php

namespace Spectra\Http\Controllers\Api;

use Illuminate\Http\Request;
use Spectra\Actions\Stats\BuildCostByModelType;
use Spectra\Actions\Stats\GetModelTypeBreakdown;
use Spectra\Actions\Stats\GetOverviewStats;
use Spectra\Actions\Stats\GetRecentRequests;
use Spectra\Actions\Stats\GetRequestsByDate;
use Spectra\Actions\Stats\GetRequestsByProvider;
use Spectra\Actions\Stats\GetTopModels;
use Spectra\Data\ModelTypeStat;
use Spectra\Data\Responses\StatsResponse;
use Spectra\Support\DateRange;

class StatsController extends BaseApiController
{
    public function __invoke(
        Request $request,
        GetOverviewStats $getOverviewStats,
        GetTopModels $getTopModels,
        GetRecentRequests $getRecentRequests,
        GetRequestsByDate $getRequestsByDate,
        GetRequestsByProvider $getRequestsByProvider,
        GetModelTypeBreakdown $getModelTypeBreakdown,
        BuildCostByModelType $buildCostByModelType,
    ): StatsResponse {
        $dateRange = DateRange::fromRequest($request);
        $layout = config('spectra.dashboard.layout', 'full');

        $stats = $getOverviewStats($dateRange, $layout);
        $modelTypeStats = $getModelTypeBreakdown($dateRange, $layout);

        return new StatsResponse(
            total_requests: $stats->total_requests,
            total_tokens: $stats->total_tokens,
            total_cost_in_cents: $stats->total_cost_in_cents,
            avg_latency: $stats->avg_latency,
            total_images: $stats->total_images,
            total_videos: $stats->total_videos,
            total_duration_seconds: $stats->total_duration_seconds,
            total_input_characters: $stats->total_input_characters,
            tts_characters: $stats->tts_characters,
            tts_duration_seconds: $stats->tts_duration_seconds,
            stt_duration_seconds: $stats->stt_duration_seconds,
            cost_by_model_type: $buildCostByModelType($modelTypeStats),
            top_models: $getTopModels($dateRange, $layout),
            recent_requests: $getRecentRequests($dateRange, $layout),
            requests_by_date: $getRequestsByDate($dateRange, $layout),
            requests_by_provider: $getRequestsByProvider($dateRange, $layout),
            latency_by_model_type: $modelTypeStats->map(fn (ModelTypeStat $row) => [
                'model_type' => $row->model_type,
                'label' => $row->label,
                'count' => $row->count,
                'avg_latency' => $row->avg_latency,
            ]),
            layout: $layout,
            stats_by_model_type: $layout === 'full'
                ? $modelTypeStats->map(fn (ModelTypeStat $row) => [
                    'model_type' => $row->model_type,
                    'label' => $row->label,
                    'count' => $row->count,
                    'cost' => $row->cost,
                ])
                : null,
        );
    }
}
