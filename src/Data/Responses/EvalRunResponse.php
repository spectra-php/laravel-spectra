<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

/**
 * One evaluation run with its results, and the run it is compared with.
 */
readonly class EvalRunResponse extends DataTransferObject
{
    /**
     * Create a new eval run response instance.
     *
     * @param  array<string, mixed>  $run
     * @param  array<string, mixed>|null  $baseline  The compared run
     * @param  array<int, array<string, mixed>>  $results  Each with the compared run's result for the same example
     * @param  array<int, array<string, mixed>>  $runs  The evaluation's other runs, to compare with
     */
    public function __construct(
        public array $run,
        public ?array $baseline,
        public array $results,
        public array $runs,
    ) {}
}
