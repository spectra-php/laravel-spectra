<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Evaluations;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Models\SpectraDataset;

/**
 * PUT /api/datasets/{id}/examples/{example}: change an example's `input` and
 * `expected`, each any JSON value. Past runs keep the values they ran with.
 * Needs the annotateSpectra gate.
 */
class UpdateDatasetExampleController extends BaseApiController
{
    /**
     * Update the example.
     */
    public function __invoke(Request $request, string $id, string $example): JsonResponse
    {
        abort_unless(Gate::allows('annotateSpectra'), 403);

        $validated = $request->validate([
            'input' => ['required'],
            'expected' => ['nullable'],
        ]);

        $datasetExample = SpectraDataset::query()->findOrFail($id)->examples()->findOrFail($example);

        $datasetExample->update([
            'input' => $validated['input'],
            'expected' => $validated['expected'] ?? null,
        ]);

        return response()->json(['updated' => true]);
    }
}
