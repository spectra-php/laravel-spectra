<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

/**
 * The maintenance screen's prices card: whether Firecrawl is set up, whether
 * requests are billed with the scraped prices file, what the file holds, the
 * providers a check would read, and the last check's changes for review.
 */
readonly class PricingStatusResponse extends DataTransferObject
{
    /**
     * Create a new pricing status response instance.
     *
     * @param  array<string, mixed>  $file
     * @param  array{source: string, providers: list<string>}  $providers_to_check
     * @param  array<string, mixed>|null  $review
     * @param  array<string, mixed>|null  $last_apply
     */
    public function __construct(
        public bool $can_manage,
        public bool $firecrawl_configured,
        public bool $uses_scraped_prices,
        public array $file,
        public array $providers_to_check,
        public ?array $review,
        public bool $pending,
        public ?array $last_apply,
    ) {}
}
