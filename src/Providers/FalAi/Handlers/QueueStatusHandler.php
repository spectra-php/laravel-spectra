<?php

declare(strict_types=1);

namespace Spectra\Providers\FalAi\Handlers;

use Illuminate\Support\Str;
use Spectra\Contracts\Handler;
use Spectra\Contracts\ReturnsBinaryResponse;
use Spectra\Contracts\SkipsResponse;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;

/**
 * fal.ai queue bookkeeping: `.../requests/{id}/status`, its event stream
 * `.../status/stream`, and `.../cancel`.
 *
 * These calls say nothing about cost, so they are never recorded; the
 * result fetch is. The handler is marked as returning a binary body only so
 * the status event stream is not parsed as a model stream.
 */
class QueueStatusHandler implements Handler, ReturnsBinaryResponse, SkipsResponse
{
    /**
     * Get the model type fal.ai queue calls would be recorded as.
     */
    public function modelType(): ModelType
    {
        return ModelType::Image;
    }

    /**
     * Get no registry patterns, since queue paths are matched by matchesEndpoint().
     */
    public function endpoints(): array
    {
        return [];
    }

    /**
     * Determine if the path is a fal.ai queue status, status stream or cancel call.
     */
    public function matchesEndpoint(string $endpoint): bool
    {
        return Str::isMatch('#^/[^/]+/[^/]+(/[^/]+)*/requests/[^/]+/(status(/stream)?|cancel)$#', $endpoint);
    }

    /**
     * Determine if the response is skipped, which queue bookkeeping always is.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function shouldSkipResponse(array $responseData): bool
    {
        return true;
    }

    /**
     * Build empty usage, since queue bookkeeping costs nothing.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        return new Metrics;
    }

    /**
     * Get no response text for queue bookkeeping.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        return null;
    }
}
