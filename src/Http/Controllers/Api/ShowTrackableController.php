<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Illuminate\Http\Request;
use Spectra\Actions\Trackables\GetTrackableDetails;
use Spectra\Data\Responses\TrackableDetailsResponse;
use Spectra\Support\DateRange;

/**
 * GET /api/trackables/view/{id}: the trackable detail screen.
 */
class ShowTrackableController extends BaseApiController
{
    /**
     * Get the trackable detail screen's data for one trackable.
     */
    public function __invoke(
        Request $request,
        string $id,
        GetTrackableDetails $getTrackableDetails,
    ): TrackableDetailsResponse {
        $type = $request->input('type');

        abort_unless((bool) $type, 400, 'Missing type parameter.');

        return $getTrackableDetails($type, $id, DateRange::fromRequest($request));
    }
}
