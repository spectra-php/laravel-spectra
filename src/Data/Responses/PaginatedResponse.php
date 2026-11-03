<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

/**
 * Base of the paginated dashboard responses: the page's rows plus the page
 * numbers and total the SPA's pager reads.
 *
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
