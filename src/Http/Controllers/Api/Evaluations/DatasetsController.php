<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Evaluations;

use Illuminate\Support\Facades\Gate;
use Spectra\Actions\Evaluations\GetDatasets;
use Spectra\Data\Responses\DatasetsResponse;
use Spectra\Http\Controllers\Api\BaseApiController;

/**
 * GET /api/datasets: every dataset with its number of examples.
 */
class DatasetsController extends BaseApiController
{
    /**
     * Get the datasets; none until the evaluation migration runs.
     */
    public function __invoke(GetDatasets $getDatasets): DatasetsResponse
    {
        if (! $getDatasets->migrated()) {
            return new DatasetsResponse(datasets: [], migrated: false, can_curate: false);
        }

        return new DatasetsResponse(datasets: $getDatasets(), migrated: true, can_curate: Gate::allows('annotateSpectra'));
    }
}
