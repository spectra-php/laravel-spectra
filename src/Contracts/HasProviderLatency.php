<?php

declare(strict_types=1);

namespace Spectra\Contracts;

/**
 * Implemented by handlers for async jobs (video generation, predictions)
 * whose real generation time is reported by the provider, because the HTTP
 * call that fetched the result only measures a status poll.
 */
interface HasProviderLatency
{
    /**
     * Get the generation time in milliseconds, or null when the response doesn't say.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function extractProviderLatency(array $responseData): ?int;
}
