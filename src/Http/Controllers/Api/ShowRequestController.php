<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Spectra\Actions\Requests\GetRequestDetails;
use Spectra\Data\Responses\RequestDetailsResponse;

/**
 * GET /api/requests/{id}: the request detail screen.
 */
class ShowRequestController extends BaseApiController
{
    public function __invoke(string $id, GetRequestDetails $action): RequestDetailsResponse
    {
        return $action($id);
    }
}
