<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Evaluations;

use Spectra\Actions\Evaluations\GetEvaluations;
use Spectra\Data\Responses\EvalsResponse;
use Spectra\Http\Controllers\Api\BaseApiController;

/**
 * GET /api/evals: each evaluation with its latest runs.
 */
class EvalsController extends BaseApiController
{
    /**
     * Get the evaluations; none until the evaluation migration runs.
     */
    public function __invoke(GetEvaluations $getEvaluations): EvalsResponse
    {
        if (! $getEvaluations->migrated()) {
            return new EvalsResponse(evaluations: [], migrated: false);
        }

        return new EvalsResponse(evaluations: $getEvaluations(), migrated: true);
    }
}
