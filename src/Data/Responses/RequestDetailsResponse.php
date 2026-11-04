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
     * Create a new request details response instance.
     *
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
    ) {}

    /**
     * Get the assembled request details array unchanged.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
