<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Evaluations;

use Illuminate\Support\Facades\Gate;
use Spectra\Actions\Evaluations\GetEvaluations;
use Spectra\Data\Responses\EvalsResponse;
use Spectra\Http\Controllers\Api\BaseApiController;

/**
 * GET /api/evals: each evaluation with its latest runs, and the datasets.
 */
class EvalsController extends BaseApiController
{
    /**
     * Get the evaluations and datasets; none until the evaluation migration runs.
     */
    public function __invoke(GetEvaluations $getEvaluations): EvalsResponse
    {
        if (! $getEvaluations->migrated()) {
            return new EvalsResponse(evaluations: [], datasets: [], migrated: false, can_curate: false);
        }

        $data = $getEvaluations();

        return new EvalsResponse(evaluations: $data['evaluations'], datasets: $data['datasets'], migrated: true, can_curate: Gate::allows('annotateSpectra'));
    }
}
