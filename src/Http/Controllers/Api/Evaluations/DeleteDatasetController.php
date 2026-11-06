<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Evaluations;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Spectra\Facades\Spectra;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Models\SpectraDataset;

/**
 * DELETE /api/datasets/{id}: delete a dataset and its examples. Past runs stay.
 * Needs the annotateSpectra gate.
 */
class DeleteDatasetController extends BaseApiController
{
    /**
     * Delete the dataset.
     */
    public function __invoke(string $id): JsonResponse
    {
        abort_unless(Gate::allows('annotateSpectra'), 403);

        Spectra::dataset(SpectraDataset::query()->findOrFail($id)->name)->delete();

        return response()->json(['deleted' => true]);
    }
}
