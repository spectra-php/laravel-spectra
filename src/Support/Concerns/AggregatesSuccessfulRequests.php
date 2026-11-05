<?php

declare(strict_types=1);

namespace Spectra\Support\Concerns;

use Spectra\Models\SpectraRequest;

/**
 * SQL fragments that aggregate cost and latency over successful requests only
 * (SpectraRequest::SUCCESSFUL_SQL: a 2xx status and no recorded error), so
 * failed requests never inflate spend or latency metrics, and that count the
 * successful and failed requests of a row set. Plain counts are left
 * unfiltered: total request numbers include failures.
 */
trait AggregatesSuccessfulRequests
{
    /**
     * Build a select that sums the cost of successful requests only, under the given alias.
     *
     * @param  literal-string  $alias
     * @return literal-string
     */
    protected function successfulCostSum(string $alias): string
    {
        return "{$this->successfulCostExpression()} as {$alias}";
    }

    /**
     * Build a select that averages the latency of successful requests only, under the given alias.
     *
     * @param  literal-string  $alias
     * @return literal-string
     */
    protected function successfulLatencyAvg(string $alias): string
    {
        return "{$this->successfulLatencyExpression()} as {$alias}";
    }

    /**
     * Get the SQL that sums the cost of successful requests, so failures never count as spend.
     *
     * @return literal-string
     */
    protected function successfulCostExpression(): string
    {
        return 'COALESCE(SUM(CASE WHEN '.SpectraRequest::SUCCESSFUL_SQL.' THEN total_cost_in_cents ELSE 0 END), 0)';
    }

    /**
     * Get the SQL that averages the latency of successful requests, null when no request succeeded.
     *
     * @return literal-string
     */
    protected function successfulLatencyExpression(): string
    {
        return 'AVG(CASE WHEN '.SpectraRequest::SUCCESSFUL_SQL.' THEN latency_ms END)';
    }

    /**
     * Get the SQL that counts the successful requests of a row set.
     *
     * @return literal-string
     */
    protected function successfulCountExpression(): string
    {
        return 'COALESCE(SUM(CASE WHEN '.SpectraRequest::SUCCESSFUL_SQL.' THEN 1 ELSE 0 END), 0)';
    }

    /**
     * Get the SQL that counts the failed requests of a row set.
     *
     * @return literal-string
     */
    protected function failedCountExpression(): string
    {
        return 'COALESCE(SUM(CASE WHEN '.SpectraRequest::SUCCESSFUL_SQL.' THEN 0 ELSE 1 END), 0)';
    }
}
