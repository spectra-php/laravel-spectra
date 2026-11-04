<?php

declare(strict_types=1);

namespace Spectra\Actions\Pricing;

use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use RuntimeException;
use Spectra\Models\SpectraRequest;
use Spectra\Pricing\ProviderPricing;
use Spectra\Support\Pricing\PricingCatalog;
use Spectra\Support\Pricing\Scraping\FirecrawlPriceScraper;
use Spectra\Support\Pricing\Scraping\PageText;
use Spectra\Support\Pricing\Scraping\ProviderScrape;
use Spectra\Support\Pricing\Scraping\ScrapedPriceMapper;

/**
 * Reads one provider's pricing pages with Firecrawl and compares the prices
 * with its built-in catalog. Used by spectra:pricing-scrape and the
 * dashboard's price check.
 */
class ScrapeProviderPrices
{
    /**
     * Create a new scrape provider prices action instance.
     */
    public function __construct(
        private readonly ScrapedPriceMapper $mapper,
    ) {}

    /**
     * Scrape the provider's pricing pages (or the given ones) and map them onto its catalog.
     *
     * @param  list<string>  $urls  Pages to read instead of the catalog's own
     *
     * @throws RuntimeException When Firecrawl fails or a page shows no prices.
     */
    public function __invoke(ProviderPricing $pricing, array $urls = []): ProviderScrape
    {
        $sources = $urls !== [] ? $urls : $pricing->sources();
        $scraper = FirecrawlPriceScraper::fromConfig();

        $rows = [];
        $markdown = [];
        foreach ($sources as $url) {
            $page = $scraper->scrape($pricing, $url);
            $rows = [...$rows, ...$page->rows];
            $markdown[] = $page->markdown;
        }

        return new ProviderScrape(
            provider: $pricing->provider(),
            currency: $pricing->currency(),
            sources: $sources,
            rows: count($rows),
            result: $this->mapper->map($pricing, $rows, page: new PageText(implode("\n", $markdown))),
        );
    }

    /**
     * Determine whether a Firecrawl API key is set.
     */
    public static function configured(): bool
    {
        return filled(config('spectra.costs.firecrawl.api_key'));
    }

    /**
     * The providers to scrape when none are named, so a run only spends
     * Firecrawl credits on the providers you use: `spectra.costs.firecrawl.providers`,
     * else the providers with pricing pages Spectra has tracked requests for.
     * Unknown configured providers are left out.
     *
     * @return array{source: 'config'|'tracked', catalogs: array<string, ProviderPricing>}
     */
    public static function defaultCatalogs(): array
    {
        $builtIn = PricingCatalog::builtIn();
        $configured = array_values(collect((array) config('spectra.costs.firecrawl.providers', []))->filter()->all());

        if ($configured !== []) {
            return ['source' => 'config', 'catalogs' => Arr::only($builtIn, $configured)];
        }

        $scrapable = Arr::where($builtIn, fn (ProviderPricing $pricing) => $pricing->sources() !== []);

        return ['source' => 'tracked', 'catalogs' => Arr::only($scrapable, static::trackedProviders())];
    }

    /**
     * Provider slugs with at least one tracked request. Empty when the
     * Spectra tables aren't there (not migrated, or another connection).
     *
     * @return list<string>
     */
    public static function trackedProviders(): array
    {
        try {
            $providers = SpectraRequest::query()->distinct()->orderBy('provider')->pluck('provider');
        } catch (QueryException) {
            return [];
        }

        return array_values($providers->map(fn (mixed $provider) => (string) $provider)->filter()->all());
    }
}
