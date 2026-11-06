<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Evaluations;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Spectra\Facades\Spectra;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Models\SpectraDataset;

/**
 * PUT /api/datasets/{id}: change a dataset's `name` and `description`. The runs
 * over it move to the new name. Needs the annotateSpectra gate.
 */
class UpdateDatasetController extends BaseApiController
{
    /**
     * Update the dataset.
     */
    public function __invoke(Request $request, string $id): JsonResponse
    {
        abort_unless(Gate::allows('annotateSpectra'), 403);

        $dataset = SpectraDataset::query()->findOrFail($id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique(SpectraDataset::class, 'name')->ignore($dataset->id)],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [
            'name.unique' => 'A dataset with this name already exists.',
        ]);

        $dataset->update(['description' => $validated['description'] ?? null]);

        Spectra::dataset($dataset->name)->rename($validated['name']);

        return response()->json(['updated' => true]);
    }
}
