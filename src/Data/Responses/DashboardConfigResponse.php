<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Illuminate\Support\Collection;
use Spectra\Data\DataTransferObject;

/**
 * The settings the SPA loads first: the configured layout and the model types
 * it can filter by.
 */
readonly class DashboardConfigResponse extends DataTransferObject
{
    /**
     * Create a new dashboard config response instance.
     *
     * @param  Collection<int, array{value: string, label: string}>  $model_types
     */
    public function __construct(
        public string $layout,
        public Collection $model_types,
    ) {}
}
