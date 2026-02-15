<?php

namespace Spectra\Contracts;

interface SkipsResponse
{
    /** @param  array<string, mixed>  $responseData */
    public function shouldSkipResponse(array $responseData): bool;
}
