<?php

declare(strict_types=1);

namespace Spectra\Providers\Google;

trait ExtractsModelFromGoogleEndpoint
{
    /** @param  array<string, mixed>  $requestData */
    public function extractModelFromRequest(array $requestData, string $endpoint): ?string
    {
        if (preg_match('#/models/([^/:]+)#', $endpoint, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
