<?php

declare(strict_types=1);

namespace Spectra\Contracts;

use Illuminate\Support\Carbon;

interface HasExpiration
{
    /** @param  array<string, mixed>  $responseData */
    public function extractExpiresAt(array $responseData): ?Carbon;
}
