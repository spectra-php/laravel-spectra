<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Illuminate\Support\Collection;
use Spectra\Data\ModelTypeLatency;

/**
 * The trackables screen: one page of trackables plus the summary, latency
 * breakdown and filter options shown around it.
 */
readonly class TrackablesResponse extends PaginatedResponse
{
    /**
     * Create a new trackables response instance.
     *
     * @param  array<int, mixed>  $data
     * @param  array<int, string>  $types
     * @param  array<int, string>  $available_tags
     * @param  array<int, string>  $available_finish_reasons
     * @param  Collection<int, ModelTypeLatency>  $latency_by_model_type
     */
    public function __construct(
        array $data,
        int $current_page,
        int $last_page,
        int $total,
        public array $types,
        public array $available_tags,
        public array $available_finish_reasons,
        public TrackablesSummaryResponse $summary,
        public Collection $latency_by_model_type,
    ) {
        parent::__construct(data: $data, current_page: $current_page, last_page: $last_page, total: $total);
    }
}
