<?php

declare(strict_types=1);

namespace Spectra\Data;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * One page of a dashboard list, and what a large table changed about it: its
 * total may be an estimate, and a sort other than newest-first may only cover
 * the latest rows.
 */
readonly class RowPage extends DataTransferObject
{
    /**
     * Create a new row page instance.
     *
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     */
    public function __construct(
        public LengthAwarePaginator $paginator,
        public bool $totalIsEstimate = false,
        public ?string $nextCursor = null,
        public ?int $sortedWithinLatest = null,
    ) {}
}
