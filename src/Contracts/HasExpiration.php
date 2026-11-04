<?php

declare(strict_types=1);

namespace Spectra\Contracts;

use Carbon\CarbonInterface;

/**
 * Implemented by handlers whose result is only downloadable for a limited
 * time (an OpenAI video), so the dashboard can tell when the provider's copy
 * is gone.
 */
interface HasExpiration
{
    /**
     * Get when the provider's copy of the result stops being downloadable.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function extractExpiresAt(array $responseData): ?CarbonInterface;
}
