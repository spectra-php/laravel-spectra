<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Evaluations;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Models\SpectraDataset;

/**
 * POST /api/datasets: create an empty dataset with a `name` and an optional
 * `description`. Needs the annotateSpectra gate.
 */
class StoreDatasetController extends BaseApiController
{
    /**
     * Create the dataset and answer with its id.
     */
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('annotateSpectra'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique(SpectraDataset::class, 'name')],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [
            'name.unique' => 'A dataset with this name already exists.',
        ]);

        $dataset = SpectraDataset::query()->create($validated);

        return new JsonResponse(['id' => $dataset->id], 201);
    }
}
