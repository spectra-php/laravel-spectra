<?php

declare(strict_types=1);

namespace Spectra\Concerns;

/**
 * Extracts the model name from a response's top-level `model` field — the
 * convention used by OpenAI-compatible APIs and most other providers.
 */
trait ExtractsModelField
{
    /** @param  array<string, mixed>  $response */
    public function extractModelFromResponse(array $response): ?string
    {
        return $response['model'] ?? null;
    }
}
