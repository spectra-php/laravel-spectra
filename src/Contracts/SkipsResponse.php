<?php

declare(strict_types=1);

namespace Spectra\Contracts;

interface SkipsResponse
{
    /** @param  array<string, mixed>  $responseData */
    public function shouldSkipResponse(array $responseData): bool;
}
