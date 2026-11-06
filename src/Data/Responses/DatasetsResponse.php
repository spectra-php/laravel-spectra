<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

/**
 * The Datasets screen: every dataset with its number of examples.
 */
readonly class DatasetsResponse extends DataTransferObject
{
    /**
     * Create a new datasets response instance.
     *
     * @param  array<int, array<string, mixed>>  $datasets
     * @param  bool  $migrated  Whether the evaluation migration has run
     * @param  bool  $can_curate  Whether the annotateSpectra gate lets the user add calls to datasets
     */
    public function __construct(
        public array $datasets,
        public bool $migrated,
        public bool $can_curate,
    ) {}
}
