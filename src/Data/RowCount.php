<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * How many rows a list holds, and whether that is exact or where counting stopped.
 */
readonly class RowCount extends DataTransferObject
{
    /**
     * Create a new row count instance.
     */
    public function __construct(
        public int $rows,
        public bool $isExact,
    ) {}
}
