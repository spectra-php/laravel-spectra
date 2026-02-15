<?php

namespace Spectra\Data\Responses;

use Illuminate\Support\Collection;
use Spectra\Data\DataTransferObject;

readonly class DashboardConfigResponse extends DataTransferObject
{
    /**
     * @param  Collection<int, array{value: string, label: string}>  $model_types
     */
    public function __construct(
        public string $layout,
        public Collection $model_types,
    ) {}
}
