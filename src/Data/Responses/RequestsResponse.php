<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

/**
 * The requests screen: one page of requests plus the tag, finish-reason and
 * reasoning-effort filter options.
 */
readonly class RequestsResponse extends PaginatedResponse
{
    /**
     * Create a new requests response instance.
     *
     * @param  array<int, mixed>  $data
     * @param  array<int, string>  $available_tags
     * @param  array<int, string>  $available_finish_reasons
     * @param  array<int, string>  $available_reasoning_efforts
     * @param  array<int, string>  $available_trackable_types
     */
    public function __construct(
        array $data,
        int $current_page,
        int $last_page,
        int $total,
        public array $available_tags,
        public array $available_finish_reasons,
        public array $available_reasoning_efforts,
        public array $available_trackable_types,
    ) {
        parent::__construct(data: $data, current_page: $current_page, last_page: $last_page, total: $total);
    }
}
