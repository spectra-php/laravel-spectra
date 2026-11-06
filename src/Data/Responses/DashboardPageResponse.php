<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

/**
 * One page of a shared dashboard's list. Its rows hold a fixed list of keys, set
 * by the action that builds them.
 */
readonly class DashboardPageResponse extends DataTransferObject
{
    /**
     * Create a new dashboard page response instance.
     *
     * @param  array<int, mixed>  $data
     */
    public function __construct(
        public array $data,
        public int $current_page,
        public int $last_page,
        public int $total,
    ) {}
}
