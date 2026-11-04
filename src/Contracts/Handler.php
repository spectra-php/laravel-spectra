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
    /**
     * Get the model type this handler's requests are recorded as.
     */
    public function modelType(): ModelType;

    /**
     * Get the endpoint paths this handler handles.
     *
     * @return array<string>
     */
    public function endpoints(): array;

    /**
     * Determine if the request path belongs to this handler.
     */
    public function matchesEndpoint(string $endpoint): bool;

    /**
     * Build the token, image, audio, video or search usage of a response.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics;

    /**
     * Get the generated text of a response, or null when it has none.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string;
}
