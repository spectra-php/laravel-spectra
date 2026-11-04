<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenAI\Handlers;

use Spectra\Contracts\HasProviderLatency;
use Spectra\Data\Metrics;
use Spectra\Providers\OpenAI\MeasuresJobLatency;

/**
 * Background responses: `GET /v1/responses/{id}` (polling, or resuming the
 * stream with `?stream=true`) and `POST /v1/responses/{id}/cancel`.
 *
 * A request made with `background: true` first answers `queued` with no
 * usage, which TextHandler skips. The response is recorded when a poll
 * returns it in a final state (`completed`, `incomplete`, `failed`,
 * `cancelled`). Every poll carries the same response id, so repeated polls
 * update one row instead of adding new ones.
 *
 * Fetching a stored response that was not created in the background is not
 * recorded again: its original request already was. The same path also
 * serves `/v1/responses/input_tokens` (a free token count), which is never
 * a response object and so is skipped too.
 */
class BackgroundResponseHandler extends TextHandler implements HasProviderLatency
{
    use MeasuresJobLatency;

    /** Sub-paths of /v1/responses that are not response ids. */
    protected const RESERVED_PATHS = ['compact'];

    /**
     * Get the background response poll and cancel endpoint paths.
     */
    public function endpoints(): array
    {
        return [
            '/v1/responses/{id}',
            '/v1/responses/{id}/cancel',
        ];
    }

    /**
     * Determine if the endpoint is a response id poll or cancel, not a reserved sub-path.
     */
    public function matchesEndpoint(string $endpoint): bool
    {
        if (! preg_match('#^/v1/responses/([^/]+)(?:/cancel)?$#', $endpoint, $matches)) {
            return false;
        }

        return ! in_array($matches[1], self::RESERVED_PATHS, true);
    }

    /**
     * Determine if the poll should not be recorded: not a background response, or still pending.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function shouldSkipResponse(array $responseData): bool
    {
        if (! self::isResponsesApi($responseData) || ($responseData['background'] ?? false) !== true) {
            return true;
        }

        return ! is_string($responseData['status'] ?? null)
            || in_array($responseData['status'], self::PENDING_STATUSES, true);
    }

    /**
     * Get the metrics of a finished background response, image output included.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $images = app(ResponsesImageHandler::class);

        if ($images->matchesResponse($responseData)) {
            return $images->extractMetrics($requestData, $responseData);
        }

        return parent::extractMetrics($requestData, $responseData);
    }

    /**
     * Never claim a response by its shape alone: without an endpoint every
     * Responses API body would look like a background poll.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchesResponse(array $data): bool
    {
        return false;
    }
}
