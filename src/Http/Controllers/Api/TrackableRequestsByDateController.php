<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Illuminate\Http\Request;
use Spectra\Actions\Trackables\GetTrackableRequestsByDate;
use Spectra\Data\Responses\RequestsByDateResponse;
use Spectra\Support\DateRange;

/**
 * GET /api/trackables/view/{id}/requests-by-date: requests per day for one
 * trackable, for the detail screen's chart.
 */
class TrackableRequestsByDateController extends BaseApiController
{
    public function __invoke(
        Request $request,
        string $id,
        GetTrackableRequestsByDate $action,
    ): RequestsByDateResponse {
        $type = $request->input('type');

        abort_unless((bool) $type, 400, 'Missing type parameter.');

        $dateRange = DateRange::fromRequest($request);

        return new RequestsByDateResponse(requests_by_date: $action($type, $id, $dateRange));
    }
}
