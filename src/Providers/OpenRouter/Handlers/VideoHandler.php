<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenRouter\Handlers;

use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasFinishReason;
use Spectra\Contracts\ReportsJobFailure;
use Spectra\Contracts\SkipsResponse;
use Spectra\Data\Metrics;
use Spectra\Data\VideoMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\OpenRouter\Concerns\ReadsReportedCost;
use Spectra\Support\Payload;

/**
 * OpenRouter's async video API.
 *
 * `POST /api/v1/videos` returns a pending job; `GET /api/v1/videos/{jobId}`
 * is polled until the job ends. Only finished jobs are recorded, under the
 * job id, so repeated polls update one row. The finished job reports its
 * cost in `usage.cost`. Job responses do not name the model, so a poll is
 * recorded without one.
 */
class VideoHandler implements Handler, HasFinishReason, ReportsJobFailure, SkipsResponse
{
    use MatchesParametricEndpoints;
    use ReadsReportedCost;

    /** Job states after which nothing changes any more. */
    protected const TERMINAL_STATES = ['completed', 'failed', 'cancelled', 'expired'];

    /**
     * Get the model type this handler records.
     */
    public function modelType(): ModelType
    {
        return ModelType::Video;
    }

    /**
     * Get the video submit and poll endpoint paths.
     */
    public function endpoints(): array
    {
        return [
            '/api/v1/videos',
            '/api/v1/videos/{jobId}',
        ];
    }

    /**
     * Determine if the video job is still running and the response should not be recorded yet.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function shouldSkipResponse(array $responseData): bool
    {
        return ! in_array($responseData['status'] ?? null, self::TERMINAL_STATES, true);
    }

    /**
     * Get the video count, requested duration and reported cost of a finished job.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $usage = is_array($responseData['usage'] ?? null) ? $responseData['usage'] : [];
        $completed = ($responseData['status'] ?? null) === 'completed';

        return new Metrics(
            video: new VideoMetrics(
                count: $completed ? max(1, count($this->outputUrls($responseData))) : 0,
                durationSeconds: is_numeric($requestData['duration'] ?? null) ? Payload::float($requestData['duration']) : null,
            ),
            reportedCostInCents: $this->costFromUsage($usage),
        );
    }

    /**
     * Get the job's video URLs, or its error.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        $urls = $this->outputUrls($response);

        if ($urls !== []) {
            return implode("\n", $urls);
        }

        return is_string($response['error'] ?? null) ? $response['error'] : null;
    }

    /**
     * Get the failure message of a video job that ended `failed`, `cancelled` or `expired`.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function extractFailure(array $responseData): ?string
    {
        $status = $responseData['status'] ?? null;

        if (! in_array($status, ['failed', 'cancelled', 'expired'], true)) {
            return null;
        }

        $error = $responseData['error'] ?? null;
        $message = is_array($error) ? ($error['message'] ?? null) : $error;

        return filled($message) ? $message : "Video job {$status}";
    }

    /**
     * Get the job's status as the finish reason.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractFinishReason(array $response): ?string
    {
        return is_string($response['status'] ?? null) ? $response['status'] : null;
    }

    /**
     * Get the job's output video URLs.
     *
     * @param  array<string, mixed>  $response
     * @return list<string>
     */
    protected function outputUrls(array $response): array
    {
        $urls = $response['unsigned_urls'] ?? $response['urls'] ?? [];

        return is_array($urls) ? array_values(array_filter($urls, is_string(...))) : [];
    }
}
