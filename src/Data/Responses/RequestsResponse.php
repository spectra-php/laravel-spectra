<?php

namespace Spectra\Data\Responses;

readonly class RequestsResponse extends PaginatedResponse
{
    /**
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
        public array $available_tags,
        public array $available_finish_reasons,
        public array $available_reasoning_efforts,
    ) {
        parent::__construct($data, $current_page, $last_page, $total);
    }
}
