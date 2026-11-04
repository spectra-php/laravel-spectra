<?php

declare(strict_types=1);

namespace Spectra\Contracts;

/**
 * Implemented by handlers whose response says why generation stopped
 * (`stop`, `length`, `tool_calls`, …). It is stored on the request and used
 * to detect tool calls when the body doesn't list them.
 */
interface HasFinishReason
{
    /**
     * Get the reason the provider gives for why generation stopped.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractFinishReason(array $response): ?string;
}
