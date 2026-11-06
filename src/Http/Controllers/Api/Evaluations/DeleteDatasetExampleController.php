<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Evaluations;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Models\SpectraDataset;

/**
 * DELETE /api/datasets/{id}/examples/{example}: delete an example. Past runs
 * keep their results for it. Needs the annotateSpectra gate.
 */
class DeleteDatasetExampleController extends BaseApiController
{
    /**
     * Delete the example.
     */
    public function __invoke(string $id, string $example): JsonResponse
    {
        abort_unless(Gate::allows('annotateSpectra'), 403);

        SpectraDataset::query()->findOrFail($id)->examples()->findOrFail($example)->delete();

        return response()->json(['deleted' => true]);
    }
}
