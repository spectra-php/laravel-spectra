<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

/**
 * One dataset with its examples and the runs over it.
 */
readonly class DatasetResponse extends DataTransferObject
{
    /**
     * Create a new dataset response instance.
     *
     * @param  array<string, mixed>  $dataset
     * @param  array<int, array<string, mixed>>  $examples
     * @param  array<int, array<string, mixed>>  $runs
     * @param  mixed  $latest_input  The newest example's input, which a new one starts from; null when there are none
     * @param  bool  $can_curate  Whether the annotateSpectra gate lets the user change examples
     */
    public function __construct(
        public array $dataset,
        public array $examples,
        public array $runs,
        public mixed $latest_input,
        public bool $can_curate,
    ) {}
}
