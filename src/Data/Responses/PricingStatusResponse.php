<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

/**
 * The maintenance screen's prices card: whether requests are billed with the
 * scraped prices file, and what the file holds.
 */
readonly class PricingStatusResponse extends DataTransferObject
{
    /**
     * Create a new pricing status response instance.
     *
     * @param  array<string, mixed>  $file
     */
    public function __construct(
        public bool $uses_scraped_prices,
        public array $file,
    ) {}
}
