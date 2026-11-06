<?php

declare(strict_types=1);

namespace Spectra\Data;

use Illuminate\Support\Collection;

/**
 * One widget on a shared dashboard, in the order the admin put it, with its data.
 */
readonly class DashboardWidgetData extends DataTransferObject
{
    /**
     * Create a new shared widget instance.
     *
     * @param  DataTransferObject|Collection<int, covariant DataTransferObject>  $data
     */
    public function __construct(
        public string $type,
        public string $size,
        public DataTransferObject|Collection $data,
    ) {}
}
