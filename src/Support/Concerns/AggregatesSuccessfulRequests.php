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
     * Build a select that sums the cost of 2xx requests only, under the given alias.
     *
     * @param  literal-string  $alias
     * @return literal-string
     */
    protected function successfulCostSum(string $alias): string
    {
        return "{$this->successfulCostExpression()} as {$alias}";
    }

    /**
     * Build a select that averages the latency of 2xx requests only, under the given alias.
     *
     * @param  literal-string  $alias
     * @return literal-string
     */
    protected function successfulLatencyAvg(string $alias): string
    {
        return "{$this->successfulLatencyExpression()} as {$alias}";
    }

    /**
     * Get the SQL that sums the cost of 2xx requests, so failures never count as spend.
     *
     * @return literal-string
     */
    protected function successfulCostExpression(): string
    {
        return 'COALESCE(SUM(CASE WHEN status_code BETWEEN 200 AND 299 THEN total_cost_in_cents ELSE 0 END), 0)';
    }

    /**
     * Get the SQL that averages the latency of 2xx requests, null when no request succeeded.
     *
     * @return literal-string
     */
    protected function successfulLatencyExpression(): string
    {
        return 'AVG(CASE WHEN status_code BETWEEN 200 AND 299 THEN latency_ms END)';
    }
}
