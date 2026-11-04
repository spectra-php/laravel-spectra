<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Illuminate\Http\Request;
use Spectra\Actions\Errors\GetErrorGroups;
use Spectra\Data\Responses\ErrorsResponse;
use Spectra\Support\DateRange;

/**
 * GET /api/errors: the period's failed calls, grouped by fingerprint.
 */
class ErrorsController extends BaseApiController
{
    /**
     * Get the failure totals and the groups of similar failures.
     */
    public function __invoke(Request $request, GetErrorGroups $getErrorGroups): ErrorsResponse
    {
        return $getErrorGroups(DateRange::fromRequest($request));
    }
}
