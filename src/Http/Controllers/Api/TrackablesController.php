<?php

namespace Spectra\Http\Controllers\Api;

use Illuminate\Http\Request;
use Spectra\Actions\Requests\GetAvailableFilters;
use Spectra\Actions\Trackables\FormatTrackablesList;
use Spectra\Actions\Trackables\GetAvailableTrackableTypes;
use Spectra\Actions\Trackables\GetFilteredTrackables;
use Spectra\Actions\Trackables\GetTrackablesSummary;
use Spectra\Actions\Trackables\ResolveTopModels;
use Spectra\Actions\Trackables\ResolveTrackableMetadata;
use Spectra\Data\ModelTypeStat;
use Spectra\Data\Responses\TrackablesResponse;
use Spectra\Queries\TrackablesLatencyByModelTypeQuery;
use Spectra\Support\DateRange;

class TrackablesController extends BaseApiController
{
    public function __invoke(
        Request $request,
        GetFilteredTrackables $getFilteredTrackables,
        GetTrackablesSummary $getTrackablesSummary,
        GetAvailableTrackableTypes $getAvailableTypes,
        GetAvailableFilters $getAvailableFilters,
        ResolveTopModels $resolveTopModels,
        ResolveTrackableMetadata $resolveTrackableMetadata,
        FormatTrackablesList $formatTrackablesList,
        TrackablesLatencyByModelTypeQuery $latencyByModelTypeQuery,
    ): TrackablesResponse {
        $dateRange = DateRange::fromRequest($request);

        $paginated = $getFilteredTrackables($request, $dateRange);

        /** @var array<int, \Spectra\Models\SpectraRequest> $items */
        $items = $paginated->items();
        $pageItems = collect($items);

        $topModels = $resolveTopModels($pageItems);
        $metadata = $resolveTrackableMetadata($pageItems);
        $filters = $getAvailableFilters();

        $latencyByModelType = ModelTypeStat::fromCollection(
            $latencyByModelTypeQuery($request, $dateRange),
        );

        return new TrackablesResponse(
            data: $formatTrackablesList($pageItems, $topModels, $metadata),
            current_page: $paginated->currentPage(),
            last_page: $paginated->lastPage(),
            total: $paginated->total(),
            types: $getAvailableTypes($dateRange),
            available_tags: $filters->tags,
            available_finish_reasons: $filters->finish_reasons,
            summary: $getTrackablesSummary($request, $dateRange),
            latency_by_model_type: $latencyByModelType->map(fn (ModelTypeStat $row) => [
                'model_type' => $row->model_type,
                'label' => $row->label,
                'count' => $row->count,
                'avg_latency' => $row->avg_latency,
            ])->all(),
        );
    }
}
