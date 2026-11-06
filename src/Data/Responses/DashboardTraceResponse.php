<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

/**
 * A trace on a shared dashboard: its totals and timeline, with no origin, tool
 * arguments or results. A cost is null on a dashboard that hides costs, and an
 * error message on one that hides messages.
 */
readonly class DashboardTraceResponse extends DataTransferObject
{
    /**
     * Create a new dashboard trace response instance.
     *
     * @param  array<int, array<string, mixed>>  $requests
     * @param  array<int, array<string, mixed>>  $spans
     */
    public function __construct(
        public string $trace_id,
        public int $request_count,
        public int $failed_count,
        public ?float $total_cost_in_cents,
        public int $total_tokens,
        public ?string $started_at,
        public int $duration_ms,
        public int $timeline_ms,
        public bool $truncated,
        public array $requests,
        public array $spans,
    ) {}
}
