<?php

declare(strict_types=1);

namespace Spectra\Providers\FalAi\Concerns;

use Illuminate\Support\Str;

/**
 * Endpoint and lifecycle rules shared by the fal.ai result handlers.
 *
 * fal.ai paths are model ids: `POST fal.run/{model}` answers directly, and
 * `POST queue.fal.run/{model}` queues the job. A queued job is polled at
 * `{model}/requests/{id}/status` and its result fetched from
 * `{model}/requests/{id}` (the official clients drop a model's sub-path
 * there, e.g. `fal-ai/flux/requests/{id}` for `fal-ai/flux/dev`). Only the
 * result is recorded, under the request id.
 */
trait HandlesFalRequests
{
    /**
     * Get placeholder patterns for the registry, since every path on the fal.ai hosts is a model id.
     *
     * The patterns go up to nine segments deep with the request suffix;
     * {@see matchesEndpoint()} does the real matching.
     *
     * @return array<string>
     */
    public function endpoints(): array
    {
        $patterns = [];
        $segments = ['/{owner}', '/{model}'];

        for ($depth = 2; $depth <= 9; $depth++) {
            $patterns[] = implode('', $segments);
            $pathIndex = $depth - 1;
            $segments[] = "/{path{$pathIndex}}";
        }

        return $patterns;
    }

    /**
     * Determine if the path is a model path (sync call or queue submit) or a queued result.
     *
     * A queued result is `.../requests/{id}` or `.../requests/{id}/response`.
     * Status and cancel calls belong to QueueStatusHandler.
     */
    public function matchesEndpoint(string $endpoint): bool
    {
        if (Str::isMatch('#/requests/[^/]+/(status(/stream)?|cancel)$#', $endpoint)) {
            return false;
        }

        if (Str::contains($endpoint, '/requests/')) {
            return Str::isMatch('#^/[^/]+/[^/]+(/[^/]+)*/requests/[^/]+(/response)?$#', $endpoint);
        }

        return Str::isMatch('#^/[^/]+/[^/]+(/[^/]+){0,6}$#', $endpoint);
    }

    /**
     * Get the model id from the path, without the `/requests/{id}` part of a queued result.
     *
     * @param  array<string, mixed>  $requestData
     */
    public function extractModelFromRequest(array $requestData, string $endpoint): ?string
    {
        $model = Str::of($endpoint)->replaceMatches('#/requests/.*$#', '')->trim('/')->toString();

        return filled($model) ? $model : null;
    }

    /**
     * Determine if the body is a queue receipt or status reply rather than a result.
     *
     * Queue receipts carry `status_url` or `cancel_url`; status replies carry
     * a queue status such as `IN_QUEUE`, `IN_PROGRESS` or `COMPLETED`.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function shouldSkipResponse(array $responseData): bool
    {
        if (isset($responseData['status_url']) || isset($responseData['cancel_url'])) {
            return true;
        }

        return in_array($responseData['status'] ?? null, [
            'IN_QUEUE', 'IN_PROGRESS', 'COMPLETED',
            'CANCELLATION_REQUESTED', 'ALREADY_COMPLETED',
        ], true);
    }

    /**
     * Get the model output, unwrapping the `payload` that webhook deliveries wrap it in.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function output(array $data): array
    {
        return is_array($data['payload'] ?? null) ? $data['payload'] : $data;
    }

    /**
     * Get the queue status of a fal.ai result as its finish reason.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractFinishReason(array $response): ?string
    {
        return is_string($response['status'] ?? null) ? $response['status'] : null;
    }
}
