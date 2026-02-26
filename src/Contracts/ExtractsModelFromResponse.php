<?php

namespace Spectra\Contracts;

interface ExtractsModelFromResponse
{
    /**
     * Extract the model name from the response data.
     *
     * @param  array<string, mixed>  $response  Parsed response body
     * @return string|null The model name, or null if not present
     */
    public function extractModelFromResponse(array $response): ?string;
}
