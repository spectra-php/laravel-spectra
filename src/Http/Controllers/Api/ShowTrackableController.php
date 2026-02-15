<?php

namespace Spectra\Http\Controllers\Api;

use Illuminate\Http\Request;
use Spectra\Actions\Trackables\GetTrackableDetails;
use Spectra\Data\Responses\TrackableDetailsResponse;

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
