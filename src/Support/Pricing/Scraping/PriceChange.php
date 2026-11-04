<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing\Scraping;

/**
 * One price that differs between a scraped page and the catalog, in cents.
 */
final class PriceChange
{
    /**
     * Create a new price change for one model, tier and price field.
     */
    public function __construct(
        public readonly string $model,
        public readonly string $tier,
        public readonly ?int $aboveInputTokens,
        public readonly string $field,
        public readonly ?float $old,
        public readonly float $new,
        public readonly string $unit,
    ) {}
}
