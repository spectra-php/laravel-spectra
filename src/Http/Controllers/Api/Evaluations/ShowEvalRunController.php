<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Evaluations;

use Illuminate\Http\Request;
use Spectra\Actions\Evaluations\GetEvalRun;
use Spectra\Data\Responses\EvalRunResponse;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Models\SpectraEvalRun;

/**
 * GET /api/evals/runs/{id}: one run with its results, compared with the
 * run in `compare` when given.
 */
class ShowEvalRunController extends BaseApiController
{
    /**
     * Get the run, its results and the compared run.
     */
    public function __invoke(Request $request, string $id, GetEvalRun $getEvalRun): EvalRunResponse
    {
        $input = $request->validate(['compare' => ['nullable', 'uuid']]);

        $run = SpectraEvalRun::query()->whereKey($id)->firstOrFail();
        $baseline = filled($input['compare'] ?? null)
            ? SpectraEvalRun::query()->where('evaluation', $run->evaluation)->whereKey($input['compare'])->firstOrFail()
            : null;

        $runDetails = $getEvalRun($run, $baseline);

        return new EvalRunResponse(run: $runDetails['run'], baseline: $runDetails['baseline'], results: $runDetails['results'], runs: $runDetails['runs']);
    }
}
