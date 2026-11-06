<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Evaluations;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spectra\Facades\Spectra;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Models\SpectraDataset;

/**
 * POST /api/datasets/{id}/examples: add an example written on the dashboard,
 * with its `input` and an optional `expected`, each any JSON value. Needs the
 * annotateSpectra gate.
 */
class StoreDatasetExampleController extends BaseApiController
{
    /**
     * Add the example and answer with the dataset and example ids.
     */
    public function __invoke(Request $request, string $id): JsonResponse
    {
        abort_unless(Gate::allows('annotateSpectra'), 403);

        $validated = $request->validate([
            'input' => ['required'],
            'expected' => ['nullable'],
        ]);

        $dataset = SpectraDataset::query()->findOrFail($id);

        $example = Spectra::dataset($dataset->name)->add(
            input: $validated['input'],
            expected: $validated['expected'] ?? null,
            metadata: ['source' => 'dashboard'],
        );

        return new JsonResponse(['dataset_id' => $example->dataset_id, 'example_id' => $example->id], 201);
    }
}
