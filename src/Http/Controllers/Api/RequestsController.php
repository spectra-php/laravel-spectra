<?php

namespace Spectra\Http\Controllers\Api;

use Illuminate\Http\Request;
use Spectra\Actions\Requests\FormatRequestsList;
use Spectra\Actions\Requests\GetAvailableFilters;
use Spectra\Actions\Requests\GetFilteredRequests;
use Spectra\Data\Responses\RequestsResponse;
use Spectra\Support\DateRange;

class RequestsController extends BaseApiController
{
    public function __invoke(
        Request $request,
        GetFilteredRequests $getFilteredRequests,
        GetAvailableFilters $getAvailableFilters,
        FormatRequestsList $formatRequestsList,
    ): RequestsResponse {
        $dateRange = DateRange::fromRequest($request);
        $paginated = $getFilteredRequests($request, $dateRange);
        $filters = $getAvailableFilters();

        return new RequestsResponse(
            data: $formatRequestsList($paginated),
            current_page: $paginated->currentPage(),
            last_page: $paginated->lastPage(),
            total: $paginated->total(),
            available_tags: $filters->tags,
            available_finish_reasons: $filters->finish_reasons,
            available_reasoning_efforts: $filters->reasoning_efforts,
        );
    }
}
