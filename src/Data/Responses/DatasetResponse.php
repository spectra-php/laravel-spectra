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
     */
    public function __construct(
        public array $dataset,
        public array $examples,
        public array $runs,
    ) {}
}
