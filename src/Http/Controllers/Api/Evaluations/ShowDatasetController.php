<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Evaluations;

use Spectra\Actions\Evaluations\GetDataset;
use Spectra\Data\Responses\DatasetResponse;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Models\SpectraDataset;

/**
 * GET /api/datasets/{id}: one dataset with its examples and the runs over it.
 */
class ShowDatasetController extends BaseApiController
{
    /**
     * Get the dataset, its examples and its runs.
     */
    public function __invoke(string $id, GetDataset $getDataset): DatasetResponse
    {
        $datasetDetails = $getDataset(SpectraDataset::query()->findOrFail($id));

        return new DatasetResponse(dataset: $datasetDetails['dataset'], examples: $datasetDetails['examples'], runs: $datasetDetails['runs']);
    }
}
