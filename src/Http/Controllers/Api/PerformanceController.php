<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Illuminate\Http\Request;
use Spectra\Actions\Stats\GetModelPerformance;
use Spectra\Data\Responses\PerformanceResponse;
use Spectra\Support\DateRange;

/**
 * GET /api/performance: error rate and latency percentiles per model for the
 * selected period, narrowed to the configured dashboard layout.
 */
class PerformanceController extends BaseApiController
{
    /**
     * Get the error rate and latency percentiles of the most used models.
     */
    public function __invoke(Request $request, GetModelPerformance $getModelPerformance): PerformanceResponse
    {
        return new PerformanceResponse(models: $getModelPerformance(
            DateRange::fromRequest($request),
            config('spectra.dashboard.layout', 'full'),
        ));
    }
}
