<?php

declare(strict_types=1);

namespace Spectra\Contracts;

use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;

/**
 * One provider endpoint (or family of endpoints sharing a response shape):
 * the paths it matches, the model type it records, and how its usage and
 * text are read.
 *
 * Providers list their handlers; everything endpoint-specific lives here so
 * a provider class stays a list of hosts and handlers. Payloads change
 * without notice, so implementations type-check what they read and never
 * throw: an unknown shape must still be recorded.
 */
interface Handler
{
    public function modelType(): ModelType;

    /** @return array<string> */
    public function endpoints(): array;

    public function matchesEndpoint(string $endpoint): bool;

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics;

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string;
}
