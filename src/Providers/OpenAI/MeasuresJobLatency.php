<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenAI;

use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Generation time of an async OpenAI job (background response, video) from
 * its `created_at` / `completed_at` timestamps.
 *
 * The HTTP call that fetched the finished job only measured a status poll,
 * so the job's own timestamps are the better latency.
 */
trait MeasuresJobLatency
{
    /**
     * Get the job's generation time in milliseconds, from its own timestamps.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function extractProviderLatency(array $responseData): ?int
    {
        $start = $this->timestampMs($responseData['created_at'] ?? null);
        $end = $this->timestampMs($responseData['completed_at'] ?? null);

        if ($start === null || $end === null || $end < $start) {
            return null;
        }

        return $end - $start;
    }

    /**
     * Unix seconds (OpenAI's format) or an ISO-8601 string, in milliseconds.
     */
    private function timestampMs(mixed $value): ?int
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) round((float) $value * 1000);
        }

        if (blank($value)) {
            return null;
        }

        try {
            return Date::parse($value)->getTimestampMs();
        } catch (Throwable) {
            return null;
        }
    }
}
