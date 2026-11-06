<?php

declare(strict_types=1);

namespace Spectra\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;
use Spectra\Actions\Pricing\ScrapeProviderPrices;
use Spectra\Enums\PricingUnit;
use Spectra\Pricing\ProviderPricing;
use Spectra\Support\Pricing\PricingCatalog;
use Spectra\Support\Pricing\PricingFile;
use Spectra\Support\Pricing\Scraping\PriceChange;
use Spectra\Support\Pricing\Scraping\ScrapedPriceMapper;
use Spectra\Support\Pricing\Scraping\ScrapedPrices;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Reads the providers' pricing pages through Firecrawl and saves the prices
 * that differ from the bundled catalog to the app's pricing file.
 *
 * Written to the bundled catalog itself (the package's
 * resources/pricing/catalog.json, how the catalog is maintained), it
 * rewrites each scraped provider's section in full: every model, with the
 * changes applied. Written to any other file, it saves only the changed and
 * new models, so the file stays a short overlay over the bundled catalog
 * and keeps following it when the package is updated.
 */
#[AsCommand(name: 'spectra:pricing-scrape')]
class ScrapePricingCommand extends Command
{
    protected $signature = 'spectra:pricing-scrape
                            {provider?* : Providers to scrape (default: spectra.costs.firecrawl.providers, else the providers your app has tracked)}
                            {--all : Scrape every provider that has pricing pages}
                            {--url=* : Scrape these pages instead of the provider\'s own (one provider only)}
                            {--path= : The pricing file to write (default: spectra.costs.pricing_path, else resources/spectra/pricing.json)}
                            {--dry-run : Show the changes without writing the file}';

    protected $description = 'Read the providers\' pricing pages with Firecrawl and save the prices that differ from the bundled catalog to your pricing file';

    /**
     * Scrape each provider's pricing pages and save the prices that differ from the catalog.
     */
    public function handle(ScrapedPriceMapper $mapper, ScrapeProviderPrices $scrapePrices): int
    {
        if (! ScrapeProviderPrices::configured()) {
            $this->components->error('Set SPECTRA_FIRECRAWL_API_KEY in your .env file. Get a key at https://firecrawl.dev.');

            return self::FAILURE;
        }

        $file = $this->targetFile();
        $bundled = PricingCatalog::bundled();
        $updatesBundled = $this->isBundled($file);

        try {
            $fileContents = $file->read();
        } catch (RuntimeException $e) {
            $this->components->error("{$e->getMessage()} Fix or delete it, then run the command again.");

            return self::FAILURE;
        }

        $catalogs = $this->catalogsToScrape($this->referenceCatalogs($bundled, $fileContents['providers'], $file->path()));

        if ($catalogs === null) {
            return self::FAILURE;
        }

        $urls = array_values(collect($this->option('url'))->filter()->all());
        $scraped = 0;

        foreach ($catalogs as $provider => $pricing) {
            $this->newLine();
            $this->components->twoColumnDetail("<fg=cyan;options=bold>{$provider}</>", implode(', ', $urls !== [] ? $urls : $pricing->sources()));

            try {
                $scrape = $scrapePrices($pricing, $urls);
            } catch (RuntimeException $e) {
                $this->components->error("{$e->getMessage()} Kept the current file entries for {$provider}.");

                continue;
            }

            $scraped++;
            $result = $scrape->result;
            $this->report($mapper, $result, $scrape->rows);

            $section = is_array($fileContents['providers'][$provider] ?? null) ? $fileContents['providers'][$provider] : [];

            // A provider the bundled catalog doesn't have is kept whole in the
            // app's file, like every provider in the bundled catalog itself.
            if ($updatesBundled || ! isset($bundled[$provider])) {
                $models = $pricing->toSection()['models'];

                foreach ($result->models as $id => $entry) {
                    $models[$id] = $entry;
                }

                $fileContents['providers'][$provider] = [...$section, ...$scrape->fileSection($models)];

                continue;
            }

            if ($result->models === []) {
                unset($fileContents['providers'][$provider]);

                continue;
            }

            $fileContents['providers'][$provider] = [...Arr::only($section, ['notes']), ...$scrape->fileSection($result->models)];
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

        ksort($fileContents['providers']);
        $file->write(['generated_at' => now()->toIso8601String(), 'providers' => $fileContents['providers']]);
        PricingCatalog::flushCache();

        $this->components->info("Saved to {$file->path()}. Review the changes before you deploy them.");

        if (! $updatesBundled && PricingCatalog::userFile()?->path() !== $file->path()) {
            $this->components->warn("Spectra is billing with the bundled catalog alone. Set SPECTRA_PRICING_PATH={$this->displayPath($file->path())} to bill with this file.");
        }

        return self::SUCCESS;
    }

    /**
     * Get the file to write: --path, else `spectra.costs.pricing_path`, else
     * the default location of the app's pricing file.
     */
    protected function targetFile(): PricingFile
    {
        $path = $this->option('path');

        if (is_string($path) && $path !== '') {
            $isAbsolute = str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;

            return new PricingFile($isAbsolute ? $path : (string) getcwd().DIRECTORY_SEPARATOR.$path);
        }

        return PricingCatalog::userFile() ?? new PricingFile(resource_path('spectra/pricing.json'));
    }

    /**
     * Determine if the file is the catalog bundled with the package.
     */
    protected function isBundled(PricingFile $file): bool
    {
        $bundled = realpath(PricingCatalog::bundledFile()->path());

        return $bundled !== false && realpath($file->path()) === $bundled;
    }

    /**
     * Get the catalogs scraped prices are compared with: the bundled
     * catalogs, plus the providers only the app's file has.
     *
     * @param  array<string, ProviderPricing>  $bundled
     * @param  array<array-key, mixed>  $sections  The file's provider sections.
     * @return array<string, ProviderPricing>
     */
    protected function referenceCatalogs(array $bundled, array $sections, string $path): array
    {
        $catalogs = $bundled;

        foreach ($sections as $provider => $section) {
            $provider = (string) $provider;

            if (! isset($catalogs[$provider]) && is_array($section)) {
                $catalogs[$provider] = ProviderPricing::fromSection($provider, $section, origin: $path);
            }
        }

        return $catalogs;
    }

    /**
     * Get the providers to scrape, so a run only spends Firecrawl credits on the
     * providers you use: the ones named on the command line, else
     * `spectra.costs.firecrawl.providers`, else the providers Spectra has
     * tracked requests for. Every provider only with --all.
     *
     * @param  array<string, ProviderPricing>  $reference
     * @return array<string, ProviderPricing>|null
     */
    protected function catalogsToScrape(array $reference): ?array
    {
        $requested = array_values(collect($this->argument('provider'))->filter()->all());

        if ($this->option('url') && count($requested) !== 1) {
            $this->components->error('--url needs exactly one provider, e.g. `spectra:pricing-scrape openai --url=https://...`.');

            return null;
        }

        if ($requested !== []) {
            return $this->resolveCatalogs($reference, $requested);
        }

        $scrapable = Arr::where($reference, fn (ProviderPricing $pricing) => $pricing->sources() !== []);

        if ($this->option('all')) {
            return $scrapable;
        }

        $configured = array_values(collect((array) config('spectra.costs.firecrawl.providers', []))->filter()->all());

        if ($configured !== []) {
            $this->components->info('Scraping the providers in SPECTRA_SCRAPE_PROVIDERS: '.implode(', ', $configured).'.');

            return $this->resolveCatalogs($reference, $configured);
        }

        $tracked = ScrapeProviderPrices::trackedProviders();
        $catalogs = Arr::only($scrapable, $tracked);

        if ($catalogs === []) {
            $this->components->error('Spectra has not tracked requests for a provider with pricing pages yet. Name the providers to scrape (`spectra:pricing-scrape openai`), set SPECTRA_SCRAPE_PROVIDERS, or pass --all.');

            return null;
        }

        $this->components->info('Scraping the providers your app has tracked requests for: '.implode(', ', array_keys($catalogs)).'.');

        return $catalogs;
    }

    /**
     * Get the catalogs for the named providers, or null after reporting an unknown one.
     *
     * @param  array<string, ProviderPricing>  $reference
     * @param  list<string>  $providers
     * @return array<string, ProviderPricing>|null
     */
    protected function resolveCatalogs(array $reference, array $providers): ?array
    {
        $catalogs = [];

        foreach ($providers as $provider) {
            if (! isset($reference[$provider])) {
                $this->components->error("No pricing catalog for '{$provider}'. Known: ".implode(', ', array_keys($reference)).'.');

                return null;
            }

            if ($reference[$provider]->sources() === [] && ! $this->option('url')) {
                $this->components->error("'{$provider}' has no pricing pages to scrape. Pass them with --url.");

                return null;
            }

            $catalogs[$provider] = $reference[$provider];
        }

        return $catalogs;
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
                ['Model', 'Tier', 'Price', 'Ours', 'Scraped', 'On the page'],
                collect($result->changes)->map(fn (PriceChange $change) => [
                    $change->model.(in_array($change->model, $result->newModels, true) ? ' <fg=green>(new)</>' : ''),
                    $change->tier.($change->aboveInputTokens !== null ? ' > '.number_format($change->aboveInputTokens) : ''),
                    $mapper->label($change->field),
                    $this->money($change->old, $change),
                    $this->money($change->new, $change),
                    Str::limit((string) $change->pageLine, 70),
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

    /**
     * Show a path inside the app relative to its root.
     */
    protected function displayPath(string $path): string
    {
        $root = base_path(DIRECTORY_SEPARATOR);

        return Str::startsWith($path, $root) ? Str::after($path, $root) : $path;
    }
}
