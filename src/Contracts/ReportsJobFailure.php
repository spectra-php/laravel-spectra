<?php

declare(strict_types=1);

namespace Spectra\Contracts;

/**
 * Implemented by handlers for async jobs whose final state can be "failed"
 * inside an HTTP 200 response (a Replicate prediction, an OpenRouter video).
 *
 * The request is still stored with its 2xx status (and any usage it was
 * billed for), but with the provider's error message, so it shows up as a
 * failed job on the request page.
 */
interface ReportsJobFailure
{
    /**
     * Get the provider's error message when the job failed, otherwise null.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function extractFailure(array $responseData): ?string;
}
