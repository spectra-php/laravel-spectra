<?php

namespace Spectra\Data\Responses;

use Illuminate\Support\Collection;
use Spectra\Data\DataTransferObject;

readonly class TrackableDetailsResponse extends DataTransferObject
{
    /**
     * @param  array<string, mixed>  $trackable
     * @param  \Illuminate\Support\Collection<int, mixed>  $requests
     */
    public function __construct(
        public array $trackable,
        public Collection $requests,
    ) {}
}
