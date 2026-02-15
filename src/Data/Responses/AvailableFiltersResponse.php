<?php

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

readonly class AvailableFiltersResponse extends DataTransferObject
{
    /**
     * @param  array<int, string>  $tags
     * @param  array<int, string>  $finish_reasons
     * @param  array<int, string>  $reasoning_efforts
     */
    public function __construct(
        public array $tags,
        public array $finish_reasons,
        public array $reasoning_efforts,
    ) {}
}
