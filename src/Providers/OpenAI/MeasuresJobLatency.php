<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenAI;

use Illuminate\Support\Facades\Date;
use Spectra\Support\Payload;

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
        $start = $this->convertToMilliseconds($responseData['created_at'] ?? null);
        $end = $this->convertToMilliseconds($responseData['completed_at'] ?? null);

        if ($start === null || $end === null || $end < $start) {
            return null;
        }

        return $end - $start;
    }

    /**
     * Convert a timestamp in Unix seconds (OpenAI's format) or ISO-8601 to milliseconds.
     */
    private function convertToMilliseconds(mixed $value): ?int
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) round(Payload::float($value) * 1000);
        }

        if (blank($value)) {
            return null;
        }

        return rescue(fn () => Date::parse($value)->getTimestampMs(), null, false);
    }
}
