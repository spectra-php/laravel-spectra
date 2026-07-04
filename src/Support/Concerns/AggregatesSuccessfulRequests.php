<?php

declare(strict_types=1);

namespace Spectra\Support\Concerns;

/**
 * SQL fragments that aggregate cost and latency over successful (2xx) requests
 * only, so failed requests never inflate spend or latency metrics. Counts are
 * intentionally left unfiltered — total request numbers include failures.
 */
trait AggregatesSuccessfulRequests
{
    /**
     * @param  literal-string  $alias
     * @return literal-string
     */
    protected function successfulCostSum(string $alias): string
    {
        return "COALESCE(SUM(CASE WHEN status_code BETWEEN 200 AND 299 THEN total_cost_in_cents ELSE 0 END), 0) as {$alias}";
    }

    /**
     * @param  literal-string  $alias
     * @return literal-string
     */
    protected function successfulLatencyAvg(string $alias): string
    {
        return "AVG(CASE WHEN status_code BETWEEN 200 AND 299 THEN latency_ms END) as {$alias}";
    }
}
