<?php

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

readonly class RequestDetailsResponse extends DataTransferObject
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
