<?php

namespace Spectra\Contracts;

use Illuminate\Support\Carbon;

interface HasExpiration
{
    /** @param  array<string, mixed>  $responseData */
    public function extractExpiresAt(array $responseData): ?Carbon;
}
