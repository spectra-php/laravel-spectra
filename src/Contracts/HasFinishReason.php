<?php

declare(strict_types=1);

namespace Spectra\Contracts;

interface HasFinishReason
{
    /** @param  array<string, mixed>  $response */
    public function extractFinishReason(array $response): ?string;
}
