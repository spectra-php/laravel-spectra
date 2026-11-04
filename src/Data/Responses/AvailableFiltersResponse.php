<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

/**
 * The filter options the request and trackable screens offer, taken from the
 * values actually stored.
 */
readonly class AvailableFiltersResponse extends DataTransferObject
{
    /**
     * Create a new available filters response instance.
     *
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
