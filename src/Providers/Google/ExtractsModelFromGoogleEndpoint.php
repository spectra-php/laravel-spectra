<?php

declare(strict_types=1);

namespace Spectra\Providers\Google;

use Illuminate\Support\Str;

/**
 * Reads the model of a Gemini request from its endpoint, since Gemini puts
 * the model in the URL (`/v1beta/models/{model}:generateContent`), not in
 * the request body.
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
        $model = Str::match('#/models/([^/:]+)#', $endpoint);

        return filled($model) ? $model : null;
    }
}
