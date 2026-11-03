<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Illuminate\Support\Collection;
use Spectra\Data\DataTransferObject;
use Spectra\Data\RequestByDate;

/**
 * The trackable detail screen's requests-per-day chart. Its JSON is the bare
 * list of rows, the shape the chart has always read.
 */
readonly class RequestsByDateResponse extends DataTransferObject
{
    /**
     * @param  Collection<int, RequestByDate>  $requests_by_date
     */
    public function __construct(
        public Collection $requests_by_date,
    ) {}

    /**
     * @return array<int, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->requests_by_date->toArray();
    }
}
