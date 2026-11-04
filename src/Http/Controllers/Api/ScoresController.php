<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Illuminate\Http\Request;
use Spectra\Actions\Scores\GetScoreSummary;
use Spectra\Data\Responses\ScoresResponse;
use Spectra\Support\DateRange;

/**
 * GET /api/scores: the overview's Scores card for the selected period.
 */
class ScoresController extends BaseApiController
{
    /**
     * Get one summary row per score name given in the period.
     */
    public function __invoke(Request $request, GetScoreSummary $getScoreSummary): ScoresResponse
    {
        return new ScoresResponse($getScoreSummary(DateRange::fromRequest($request)));
    }
}
