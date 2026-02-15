<?php

namespace Spectra\Contracts;

interface HasFinishReason
{
    /** @param  array<string, mixed>  $response */
    public function extractFinishReason(array $response): ?string;
}
