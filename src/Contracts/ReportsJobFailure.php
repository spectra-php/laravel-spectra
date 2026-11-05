<?php

declare(strict_types=1);

namespace Spectra\Contracts;

/**
 * Implemented by handlers for async jobs whose final state can be "failed"
 * inside an HTTP 200 response (a Replicate prediction, an OpenRouter video).
 *
 * The request keeps its 2xx status code and any usage the job reported, and
 * is stored with the provider's error message as its error. An error on a
 * 2xx answer counts as a failed request everywhere (see SpectraRequest),
 * so the job is a failure in the counts, the error groups and the daily
 * stats, and its cost stays out of the spend totals.
 */
interface ReportsJobFailure
{
    /**
     * Get the provider's error message when the job failed, otherwise null.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function extractFailure(array $responseData): ?string;
}
