<?php

declare(strict_types=1);

namespace Spectra\Providers\Google;

/**
 * Gemini puts the model in the URL (`/v1beta/models/{model}:generateContent`),
 * not in the request body.
 */
trait ExtractsModelFromGoogleEndpoint
{
    /**
     * Get the model from the `/models/{model}` segment of the endpoint.
     *
     * @param  array<string, mixed>  $requestData
     */
    public function extractModelFromRequest(array $requestData, string $endpoint): ?string
    {
        if (preg_match('#/models/([^/:]+)#', $endpoint, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
