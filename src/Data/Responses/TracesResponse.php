<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\TracesSummary;

/**
 * The traces screen: one page of traces, the totals over every trace the
 * filters keep, and the tag, finish-reason and reasoning-effort filter
 * options.
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
    ) {
        parent::__construct(data: $data, current_page: $current_page, last_page: $last_page, total: $total);
    }
}
