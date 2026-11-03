<?php

declare(strict_types=1);

namespace Spectra\Providers\FalAi\Concerns;

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
     * Placeholder patterns for the registry: every path on the fal.ai hosts
     * is a model id, up to nine segments deep with the request suffix.
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
            $segments[] = '/{path'.($depth - 1).'}';
        }

        return $patterns;
    }

    /**
     * A model path (sync call or queue submit) or a queued result,
     * `.../requests/{id}` or `.../requests/{id}/response`. Status and cancel
     * calls belong to QueueStatusHandler.
     */
    public function matchesEndpoint(string $endpoint): bool
    {
        if (preg_match('#/requests/[^/]+/(status(/stream)?|cancel)$#', $endpoint)) {
            return false;
        }

        if (str_contains($endpoint, '/requests/')) {
            return (bool) preg_match('#^/[^/]+/[^/]+(/[^/]+)*/requests/[^/]+(/response)?$#', $endpoint);
        }

        return (bool) preg_match('#^/[^/]+/[^/]+(/[^/]+){0,6}$#', $endpoint);
    }

    /**
     * The model id is the path, without the `/requests/{id}` part of a
     * queued result.
     *
     * @param  array<string, mixed>  $requestData
     */
    public function extractModelFromRequest(array $requestData, string $endpoint): ?string
    {
        $model = trim((string) preg_replace('#/requests/.*$#', '', $endpoint), '/');

        return filled($model) ? $model : null;
    }

    /**
     * Queue receipts (`status_url`, `cancel_url`) and status replies
     * (`IN_QUEUE`, `IN_PROGRESS`, `COMPLETED`) are not results.
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
     * The model output. Webhook deliveries wrap it in `payload`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function output(array $data): array
    {
        return is_array($data['payload'] ?? null) ? $data['payload'] : $data;
    }

    /** @param  array<string, mixed>  $response */
    public function extractFinishReason(array $response): ?string
    {
        return is_string($response['status'] ?? null) ? $response['status'] : null;
    }
}
