<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\TracesSummary;

/**
 * The traces screen: one page of traces, the totals over every trace the
 * filters keep, and the tag, finish-reason and reasoning-effort filter
 * options. Until the trace migrations run there is nothing to list, and
 * `migrated` says so.
 */
readonly class TracesResponse extends PaginatedResponse
{
    /**
     * Create a new traces response instance.
     *
     * @param  array<int, mixed>  $data
     * @param  array<int, string>  $available_tags
     * @param  array<int, string>  $available_finish_reasons
     * @param  array<int, string>  $available_reasoning_efforts
     */
    public function __construct(
        array $data,
        int $current_page,
        int $last_page,
        int $total,
        public TracesSummary $summary,
        public array $available_tags,
        public array $available_finish_reasons,
        public array $available_reasoning_efforts,
        public bool $migrated = true,
    ) {
        parent::__construct(data: $data, current_page: $current_page, last_page: $last_page, total: $total);
    }

    /**
     * Build the empty response the screen gets until the trace migrations run.
     */
    public static function unmigrated(AvailableFiltersResponse $filters): self
    {
        return new self(
            data: [],
            current_page: 1,
            last_page: 1,
            total: 0,
            summary: new TracesSummary(trace_count: 0, failed_trace_count: 0, total_cost_in_cents: 0.0, avg_cost_in_cents: 0.0, avg_request_count: 0.0, avg_duration_ms: 0),
            available_tags: $filters->tags,
            available_finish_reasons: $filters->finish_reasons,
            available_reasoning_efforts: $filters->reasoning_efforts,
            migrated: false,
        );
    }
}
