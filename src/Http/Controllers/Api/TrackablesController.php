<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Illuminate\Http\Request;
use Spectra\Actions\Requests\GetAvailableFilters;
use Spectra\Actions\Trackables\FormatTrackablesList;
use Spectra\Actions\Trackables\GetFilteredTrackables;
use Spectra\Actions\Trackables\GetTrackablesLatencyByModelType;
use Spectra\Actions\Trackables\GetTrackablesSummary;
use Spectra\Actions\Trackables\GetUserTrackableTypes;
use Spectra\Actions\Trackables\ResolveTopModels;
use Spectra\Actions\Trackables\ResolveTrackableMetadata;
use Spectra\Data\Responses\TrackablesResponse;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;

/**
 * GET /api/trackables: the trackables screen, a filtered and sorted page of
 * trackables with their summary, latency breakdown and filter options.
 * `users_only` limits all of it to users, for the users screen.
 */
class TrackablesController extends BaseApiController
{
    /**
     * Get a filtered, sorted page of trackables with their summary and filter options.
     */
    public function __invoke(
        Request $request,
        GetFilteredTrackables $getFilteredTrackables,
        GetTrackablesSummary $getTrackablesSummary,
        GetTrackablesLatencyByModelType $getLatencyByModelType,
        GetAvailableFilters $getAvailableFilters,
        ResolveTopModels $resolveTopModels,
        ResolveTrackableMetadata $resolveTrackableMetadata,
        FormatTrackablesList $formatTrackablesList,
        GetUserTrackableTypes $getUserTrackableTypes,
    ): TrackablesResponse {
        $dateRange = DateRange::fromRequest($request);

        // Resolved once here: the list, its summary and its latency breakdown all filter by it.
        $userTypes = $request->boolean('users_only') ? $getUserTrackableTypes() : null;

        if ($userTypes !== null) {
            $request->merge(['trackable_types' => $userTypes]);
        }

        $paginated = $getFilteredTrackables($request, $dateRange);

        /** @var array<int, SpectraRequest> $items */
        $items = $paginated->items();
        $pageItems = collect($items);

        $topModels = $resolveTopModels($pageItems);
        $metadata = $resolveTrackableMetadata($pageItems);
        $filters = $getAvailableFilters();

        return new TrackablesResponse(
            data: $formatTrackablesList($pageItems, $topModels, $metadata),
            current_page: $paginated->currentPage(),
            last_page: $paginated->lastPage(),
            total: $paginated->total(),
            available_tags: $filters->tags,
            available_finish_reasons: $filters->finish_reasons,
            available_trackable_types: $userTypes ?? $filters->trackable_types,
            summary: $getTrackablesSummary($request, $dateRange),
            latency_by_model_type: $getLatencyByModelType($request, $dateRange),
        );
    }
}
