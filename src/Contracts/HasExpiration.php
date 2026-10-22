<?php

declare(strict_types=1);

namespace Spectra\Contracts;

use Carbon\CarbonInterface;

interface HasExpiration
{
    /** @param  array<string, mixed>  $responseData */
    public function extractExpiresAt(array $responseData): ?CarbonInterface;
}
