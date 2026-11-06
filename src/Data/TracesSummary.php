<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * The totals above the traces list, over every trace the filters keep (not
 * just the page shown).
 */
readonly class TracesSummary extends DataTransferObject
{
    /**
     * Create a new traces summary instance.
     */
    public function __construct(
        public int $trace_count,
        public int $failed_trace_count,
        public float $total_cost_in_cents,
        public float $avg_cost_in_cents,
        public float $avg_request_count,
        public int $avg_duration_ms,
        public bool $estimated = false,
    ) {}
}
