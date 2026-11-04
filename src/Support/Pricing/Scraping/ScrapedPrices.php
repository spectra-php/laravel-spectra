<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing\Scraping;

/**
 * What a provider's scraped pricing pages change in its catalog.
 */
final class ScrapedPrices
{
    /**
     * Create a new result of mapping a provider's scraped rows onto its catalog.
     *
     * @param  array<string, array<string, mixed>>  $models  Model id => scraped prices file entry, for changed and new models only.
     * @param  list<PriceChange>  $changes
     * @param  list<string>  $newModels
     * @param  list<string>  $skipped  Why scraped rows were not used.
     */
    public function __construct(
        public readonly array $models,
        public readonly array $changes,
        public readonly array $newModels,
        public readonly array $skipped,
        public readonly int $matchedModels,
    ) {}
}
