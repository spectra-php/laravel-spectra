<?php

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

/**
 * @phpstan-type PaginatedData array<int, mixed>
 */
readonly class PaginatedResponse extends DataTransferObject
{
    /**
     * @param  array<int, mixed>  $data
     */
    public function __construct(
        public array $data,
        public int $current_page,
        public int $last_page,
        public int $total,
    ) {}
}
