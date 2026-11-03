<?php

declare(strict_types=1);

namespace Spectra\Contracts;

/**
 * Implemented by handlers for async jobs, whose submit and in-progress polls
 * must not be recorded: only the response that finishes the job is billed,
 * so it is recorded once.
 */
interface SkipsResponse
{
    /** @param  array<string, mixed>  $responseData */
    public function shouldSkipResponse(array $responseData): bool;
}
