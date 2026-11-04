<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Illuminate\Support\Collection;
use Spectra\Data\DataTransferObject;

/**
 * The trackable detail screen: the trackable's stats and breakdowns, and its
 * latest requests.
 */
readonly class TrackableDetailsResponse extends DataTransferObject
{
    /**
     * Create a new trackable details response instance.
     *
     * @param  array<string, mixed>  $trackable
     * @param  Collection<int, mixed>  $requests
     */
    public function __construct(
        public array $trackable,
        public Collection $requests,
    ) {}
}
