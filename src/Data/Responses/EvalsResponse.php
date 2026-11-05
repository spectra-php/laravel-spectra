<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

/**
 * The Evals screen: each evaluation with its latest runs, and the datasets.
 */
readonly class EvalsResponse extends DataTransferObject
{
    /**
     * Create a new evals response instance.
     *
     * @param  array<int, array{name: string, dataset: string, runs: array<int, array<string, mixed>>}>  $evaluations
     * @param  array<int, array<string, mixed>>  $datasets
     * @param  bool  $migrated  Whether the evaluation migration has run
     * @param  bool  $can_curate  Whether the annotateSpectra gate lets the user add calls to datasets
     */
    public function __construct(
        public array $evaluations,
        public array $datasets,
        public bool $migrated,
        public bool $can_curate,
    ) {}
}
