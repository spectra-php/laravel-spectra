<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

/**
 * The request detail screen. Its payload is assembled by GetRequestDetails as
 * one array, because it is the stored row plus extras (payloads, media URLs,
 * trace), and it serialises to that array unchanged.
 */
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
