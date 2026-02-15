<?php

namespace Spectra\Data\Responses;

readonly class TrackablesResponse extends PaginatedResponse
{
    /**
     * @param  array<int, mixed>  $data
     * @param  array<int, string>  $types
     * @param  array<int, string>  $available_tags
     * @param  array<int, string>  $available_finish_reasons
     * @param  array<int, array<string, mixed>>  $latency_by_model_type
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
        public array $latency_by_model_type = [],
    ) {
        parent::__construct($data, $current_page, $last_page, $total);
    }
}
