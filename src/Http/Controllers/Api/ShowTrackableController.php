<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Illuminate\Http\Request;
use Spectra\Actions\Trackables\GetTrackableDetails;
use Spectra\Data\Responses\TrackableDetailsResponse;

/**
 * GET /api/trackables/view/{id}: the trackable detail screen.
 */
class ShowTrackableController extends BaseApiController
{
    public function __invoke(
        Request $request,
        string $id,
        GetTrackableDetails $action,
    ): TrackableDetailsResponse {
        return $action($request, $id);
    }
}
