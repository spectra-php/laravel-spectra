<?php

declare(strict_types=1);

namespace Spectra\Actions\Evaluations;

use Illuminate\Support\Facades\Schema;
use Spectra\Models\SpectraDataset;
use stdClass;

/**
 * The Datasets screen: every dataset with its number of examples.
 */
class GetDatasets
{
    /**
     * Get the datasets, by name.
     *
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(): array
    {
        $counts = SpectraDataset::query()->toBase()
            ->leftJoin('spectra_dataset_examples', 'spectra_dataset_examples.dataset_id', '=', 'spectra_datasets.id')
            ->groupBy('spectra_datasets.id')
            ->selectRaw('spectra_datasets.id as id, COUNT(spectra_dataset_examples.id) as example_count')
            ->get()
            ->mapWithKeys(fn (stdClass $row) => [(string) $row->id => (int) $row->example_count]);

        return SpectraDataset::query()->orderBy('name')->get()->map(fn (SpectraDataset $dataset) => [
            'id' => $dataset->id,
            'name' => $dataset->name,
            'description' => $dataset->description,
            'example_count' => $counts->get($dataset->id, 0),
            'updated_at' => $dataset->updated_at?->toIso8601String(),
        ])->all();
    }

    /**
     * Determine if the evaluation migration, which adds the dataset tables, has run.
     */
    public function migrated(): bool
    {
        $dataset = new SpectraDataset;

        return rescue(fn () => Schema::connection($dataset->getConnectionName())->hasTable($dataset->getTable()), false, report: false);
    }
}
