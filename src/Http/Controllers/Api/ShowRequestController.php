<?php

namespace Spectra\Http\Controllers\Api;

use Spectra\Actions\Requests\GetRequestDetails;
use Spectra\Data\Responses\RequestDetailsResponse;

class ShowRequestController extends BaseApiController
{
    public function __invoke(string $id, GetRequestDetails $action): RequestDetailsResponse
    {
        return $action($id);
    }
}
