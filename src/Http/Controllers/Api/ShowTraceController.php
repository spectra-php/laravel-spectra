<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Spectra\Actions\Traces\GetTraceDetails;
use Spectra\Data\Responses\TraceDetailsResponse;

/**
 * GET /api/traces/{traceId}: the trace screen.
 */
class ShowTraceController extends BaseApiController
{
    /** Calls the trace screen shows; the requests list, filtered by trace, has the rest. */
    private const CALL_LIMIT = 500;

    public function __invoke(string $traceId, GetTraceDetails $getTraceDetails): TraceDetailsResponse
    {
        return $getTraceDetails($traceId, limit: self::CALL_LIMIT) ?? abort(404);
    }
}
