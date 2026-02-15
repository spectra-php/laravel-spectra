<?php

namespace Spectra\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spectra\Actions\Trackables\GetTrackableRequestsByDate;
use Spectra\Support\DateRange;

class TrackableRequestsByDateController extends BaseApiController
{
    public function __invoke(
        Request $request,
        string $id,
        GetTrackableRequestsByDate $action,
    ): JsonResponse {
        $type = $request->input('type');

        abort_unless((bool) $type, 400, 'Missing type parameter.');

        $dateRange = DateRange::fromRequest($request);

        return response()->json($action($type, $id, $dateRange));
    }
}
