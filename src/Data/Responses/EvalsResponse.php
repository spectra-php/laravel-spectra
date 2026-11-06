<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

/**
 * The Evals screen: each evaluation with its latest runs.
 */
readonly class EvalsResponse extends DataTransferObject
{
    /**
     * Create a new evals response instance.
     *
     * @param  array<int, array{name: string, dataset: string, runs: array<int, array<string, mixed>>}>  $evaluations
     * @param  bool  $migrated  Whether the evaluation migration has run
     */
    public function __construct(
        public array $evaluations,
        public bool $migrated,
    ) {}
}
