<?php

namespace Spectra\Contracts;

interface ExtractsModelFromRequest
{
    /**
     * Extract the model name from the request data and endpoint.
     *
     * @param  array<string, mixed>  $requestData  Parsed request body
     * @param  string  $endpoint  Request endpoint path
     * @return string|null The model name, or null to fall back to default
     */
    public function extractModelFromRequest(array $requestData, string $endpoint): ?string;
}
