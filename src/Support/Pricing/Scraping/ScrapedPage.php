<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing\Scraping;

/**
 * One pricing page read by Firecrawl: the extracted rows and the page text
 * they are checked against.
 */
final class ScrapedPage
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        public readonly string $url,
        public readonly array $rows,
        public readonly string $markdown,
    ) {}
}
