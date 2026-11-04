<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing\Scraping;

/**
 * One provider's pricing pages read by Firecrawl and compared with its
 * built-in catalog.
 */
final class ProviderScrape
{
    /**
     * Create a new provider scrape result.
     *
     * @param  list<string>  $sources  The pages that were read
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $currency,
        public readonly array $sources,
        public readonly int $rows,
        public readonly ScrapedPrices $result,
    ) {}

    /**
     * Get the provider's section of the scraped prices file, with the given models.
     *
     * @param  array<string, array<string, mixed>>  $models  Model id => file entry
     * @return array{currency: string, scraped_at: string, sources: list<string>, models: array<string, array<string, mixed>>}
     */
    public function fileSection(array $models): array
    {
        return [
            'currency' => strtoupper($this->currency),
            'scraped_at' => now()->toIso8601String(),
            'sources' => $this->sources,
            'models' => $models,
        ];
    }
}
