<?php

declare(strict_types=1);

namespace Spectra\Contracts;

/**
 * Implemented by handlers that read the model the provider actually served
 * from the response. It is stored as the snapshot when it differs from the
 * requested model (`gpt-5` answered by a dated `gpt-5-2026-…`).
 */
interface ExtractsModelFromResponse
{
    /**
     * Extract the model name from the response data, or null if it is not present.
     *
     * @param  array<string, mixed>  $response  Parsed response body
     */
    public function extractModelFromResponse(array $response): ?string;
}
