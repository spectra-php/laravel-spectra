<?php

declare(strict_types=1);

namespace Spectra\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use RuntimeException;
use Spectra\Enums\PricingUnit;
use Spectra\Models\SpectraRequest;
use Spectra\Pricing\ProviderPricing;
use Spectra\Support\Pricing\PricingCatalog;
use Spectra\Support\Pricing\ScrapedPricesFile;
use Spectra\Support\Pricing\Scraping\FirecrawlPriceScraper;
use Spectra\Support\Pricing\Scraping\PageText;
use Spectra\Support\Pricing\Scraping\PriceChange;
use Spectra\Support\Pricing\Scraping\ScrapedPriceMapper;
use Spectra\Support\Pricing\Scraping\ScrapedPrices;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Reads the providers' pricing pages through Firecrawl and saves the prices
 * that differ from the built-in catalog to the scraped prices file.
 *
 * Built-in prices are maintained by hand and can lag a provider's change;
 * the file lets an app bill with fresh prices before a Spectra release.
 */
#[AsCommand(name: 'spectra:pricing-scrape')]
class ScrapePricingCommand extends Command
{
    protected $signature = 'spectra:pricing-scrape
                            {provider?* : Providers to scrape (default: spectra.costs.firecrawl.providers, else the providers your app has tracked)}
                            {--all : Scrape every provider that has pricing pages}
                            {--url=* : Scrape these pages instead of the provider\'s own (one provider only)}
                            {--path= : The scraped prices file (default: spectra.costs.scraped_prices_path)}
                            {--dry-run : Show the changes without writing the file}';

    protected $description = 'Read the providers\' pricing pages with Firecrawl and save the prices that differ from the built-in catalog to the scraped prices file';

    /**
     * Scrape each provider's pricing pages and save the prices that differ from the catalog.
     */
    public function handle(ScrapedPriceMapper $mapper): int
    {
        if (! config('spectra.costs.firecrawl.api_key')) {
            $this->components->error('Set SPECTRA_FIRECRAWL_API_KEY in your .env file. Get a key at https://firecrawl.dev.');

            return self::FAILURE;
        }

        $catalogs = $this->catalogsToScrape();

        if ($catalogs === null) {
            return self::FAILURE;
        }

        $file = $this->option('path') ? new ScrapedPricesFile($this->option('path')) : PricingCatalog::scrapedPricesFile();

        try {
            $data = $file->read();
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage().' Fix or delete it, then run the command again.');

            return self::FAILURE;
        }

        $scraper = FirecrawlPriceScraper::fromConfig();
        $urls = collect($this->option('url'))->filter()->values()->all();
        $scraped = 0;

        foreach ($catalogs as $provider => $pricing) {
            $sources = $urls !== [] ? $urls : $pricing->sources();

            $this->newLine();
            $this->components->twoColumnDetail("<fg=cyan;options=bold>{$provider}</>", implode(', ', $sources));

            try {
                $rows = [];
                $markdown = [];
                foreach ($sources as $url) {
                    $page = $scraper->scrape($pricing, $url);
                    $rows = [...$rows, ...$page->rows];
                    $markdown[] = $page->markdown;
                }
            } catch (RuntimeException $e) {
                $this->components->error($e->getMessage().' Kept the current file entries for '.$provider.'.');

                continue;
            }

            $scraped++;
            $result = $mapper->map($pricing, $rows, page: new PageText(implode("\n", $markdown)));
            $this->report($mapper, $result, count($rows));

            if ($result->models === []) {
                unset($data['providers'][$provider]);

                continue;
            }

            $data['providers'][$provider] = [
                'currency' => strtoupper($pricing->currency()),
                'scraped_at' => now()->toIso8601String(),
                'sources' => $sources,
                'models' => $result->models,
            ];
        }

        if ($scraped === 0) {
            $this->components->error('No provider could be scraped. Nothing was written.');

            return self::FAILURE;
        }

        $this->newLine();

        if ($this->option('dry-run')) {
            $this->components->info('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        ksort($data['providers']);
        $file->write(['generated_at' => now()->toIso8601String(), 'providers' => $data['providers']]);

        $this->components->info("Saved to {$file->path()}. Review the changes before you deploy them.");

        if (! PricingCatalog::usesScrapedPrices()) {
            $this->components->warn('Spectra is using the built-in prices. Set SPECTRA_USE_SCRAPED_PRICES=true to bill with this file.');
        }

        return self::SUCCESS;
    }

    /**
     * The providers to scrape, so a run only spends Firecrawl credits on the
     * providers you use: the ones named on the command line, else
     * `spectra.costs.firecrawl.providers`, else the providers Spectra has
     * tracked requests for. Every provider only with --all.
     *
     * @return array<string, ProviderPricing>|null
     */
    protected function catalogsToScrape(): ?array
    {
        $builtIn = PricingCatalog::builtIn();
        $requested = array_values(collect($this->argument('provider'))->filter()->all());

        if ($this->option('url') && count($requested) !== 1) {
            $this->components->error('--url needs exactly one provider, e.g. `spectra:pricing-scrape openai --url=https://...`.');

            return null;
        }

        if ($requested !== []) {
            return $this->resolveCatalogs($builtIn, $requested);
        }

        $scrapable = Arr::where($builtIn, fn (ProviderPricing $pricing) => $pricing->sources() !== []);

        if ($this->option('all')) {
            return $scrapable;
        }

        $configured = array_values(collect((array) config('spectra.costs.firecrawl.providers', []))->filter()->all());

        if ($configured !== []) {
            $this->components->info('Scraping the providers in SPECTRA_SCRAPE_PROVIDERS: '.implode(', ', $configured).'.');

            return $this->resolveCatalogs($builtIn, $configured);
        }

        $tracked = $this->trackedProviders();
        $catalogs = Arr::only($scrapable, $tracked);

        if ($catalogs === []) {
            $this->components->error('Spectra has not tracked requests for a provider with pricing pages yet. Name the providers to scrape (`spectra:pricing-scrape openai`), set SPECTRA_SCRAPE_PROVIDERS, or pass --all.');

            return null;
        }

        $this->components->info('Scraping the providers your app has tracked requests for: '.implode(', ', array_keys($catalogs)).'.');

        return $catalogs;
    }

    /**
     * Get the pricing catalogs for the named providers, or null after reporting an unknown one.
     *
     * @param  array<string, ProviderPricing>  $builtIn
     * @param  list<string>  $providers
     * @return array<string, ProviderPricing>|null
     */
    protected function resolveCatalogs(array $builtIn, array $providers): ?array
    {
        $catalogs = [];

        foreach ($providers as $provider) {
            if (! isset($builtIn[$provider])) {
                $this->components->error("No pricing catalog for '{$provider}'. Known: ".implode(', ', array_keys($builtIn)).'.');

                return null;
            }

            if ($builtIn[$provider]->sources() === [] && ! $this->option('url')) {
                $this->components->error("'{$provider}' has no pricing pages to scrape. Pass them with --url.");

                return null;
            }

            $catalogs[$provider] = $builtIn[$provider];
        }

        return $catalogs;
    }

    /**
     * Provider slugs with at least one tracked request. Empty when the
     * Spectra tables aren't there (not migrated, or another connection).
     *
     * @return list<string>
     */
    protected function trackedProviders(): array
    {
        try {
            $providers = SpectraRequest::query()->distinct()->orderBy('provider')->pluck('provider');
        } catch (QueryException) {
            return [];
        }

        return array_values($providers->map(fn (mixed $provider) => (string) $provider)->filter()->all());
    }

    /**
     * Print the rows found, the price changes and the skipped rows for one provider.
     */
    protected function report(ScrapedPriceMapper $mapper, ScrapedPrices $result, int $rows): void
    {
        $this->components->twoColumnDetail(
            "{$rows} rows, {$result->matchedModels} catalog models found",
            count($result->changes).' price changes, '.count($result->newModels).' new models',
        );

        if ($result->changes !== []) {
            $this->table(
                ['Model', 'Tier', 'Price', 'Ours', 'Scraped'],
                collect($result->changes)->map(fn (PriceChange $change) => [
                    $change->model.(in_array($change->model, $result->newModels, true) ? ' <fg=green>(new)</>' : ''),
                    $change->tier.($change->aboveInputTokens !== null ? ' > '.number_format($change->aboveInputTokens) : ''),
                    $mapper->label($change->field),
                    $this->money($change->old, $change),
                    $this->money($change->new, $change),
                ])->all(),
            );
        }

        foreach ($result->skipped as $reason) {
            $this->components->warn($reason);
        }
    }

    /**
     * Format a price in cents as a currency amount per 1M tokens, per 1M characters or per unit.
     */
    protected function money(?float $cents, PriceChange $change): string
    {
        if ($cents === null) {
            return '—';
        }

        $amount = rtrim(rtrim(number_format($cents / 100, 6, '.', ''), '0'), '.');

        return match (true) {
            $change->field !== 'price_per_unit' => "{$amount} /1M",
            $change->unit === PricingUnit::Characters->value => "{$amount} /1M chars",
            default => "{$amount} /{$change->unit}",
        };
    }
}
