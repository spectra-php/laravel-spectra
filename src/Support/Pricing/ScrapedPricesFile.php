<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Spectra\Pricing\ProviderPricing;
use Spectra\Pricing\ScrapedPricing;

/**
 * Reads and writes the scraped prices file (JSON).
 *
 * Shape:
 *
 *     {
 *         "generated_at": "2026-10-31T09:00:00+00:00",
 *         "providers": {
 *             "openai": {
 *                 "currency": "USD",
 *                 "models": {
 *                     "gpt-4o": {
 *                         "display_name": "GPT-4o",
 *                         "capabilities": ["text"],
 *                         "tiers": [{"tier": "standard", "input_price": 250, "output_price": 1000}]
 *                     }
 *                 }
 *             }
 *         }
 *     }
 *
 * Prices are cents per 1M tokens (or per unit), like the PHP catalogs.
 */
class ScrapedPricesFile
{
    /**
     * Create a new scraped prices file instance for the given path.
     */
    public function __construct(
        protected string $path,
    ) {}

    /**
     * Get the path of the scraped prices file.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Determine if the scraped prices file has been written yet.
     */
    public function exists(): bool
    {
        return File::exists($this->path);
    }

    /**
     * Get the decoded file, or an empty catalog when it doesn't exist yet.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the file isn't a valid pricing file.
     */
    public function read(): array
    {
        if (! $this->exists()) {
            return ['providers' => []];
        }

        try {
            $scrapedPrices = json_decode(File::get($this->path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("{$this->path} is not valid JSON: {$e->getMessage()}", $e->getCode(), previous: $e);
        }

        if (! is_array($scrapedPrices) || ! is_array($scrapedPrices['providers'] ?? null)) {
            throw new RuntimeException("{$this->path} has no \"providers\" object.");
        }

        return $scrapedPrices;
    }

    /**
     * Write the scraped prices as pretty-printed JSON, creating the folder if needed.
     *
     * @param  array<string, mixed>  $data
     */
    public function write(array $data): void
    {
        File::ensureDirectoryExists(dirname($this->path));
        File::put($this->path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * Get one catalog per provider in the file.
     *
     * A provider whose currency differs from its built-in catalog is skipped:
     * its models would be converted with the wrong exchange rate, and an
     * entry whose pricing unit differs from its built-in model's is skipped
     * too. Problems are logged instead of thrown, so cost tracking keeps the
     * built-in prices.
     *
     * @param  array<string, ProviderPricing>  $builtIn  Provider slug => built-in catalog.
     * @return list<ScrapedPricing>
     */
    public function catalogs(array $builtIn = []): array
    {
        if (! $this->exists()) {
            Log::warning("Spectra: the scraped prices file {$this->path} doesn't exist; using the built-in prices. Run `php artisan spectra:pricing-scrape` to create it.");

            return [];
        }

        try {
            $providers = $this->read()['providers'];
        } catch (RuntimeException $e) {
            Log::warning("Spectra: {$e->getMessage()} Using the built-in prices.");

            return [];
        }

        $catalogs = [];

        foreach ($providers as $provider => $section) {
            $provider = (string) $provider;

            if (! is_array($section) || ! is_array($section['models'] ?? null)) {
                Log::warning("Spectra: the scraped prices file has no models for {$provider}; using its built-in prices.");

                continue;
            }

            $builtInCurrency = isset($builtIn[$provider]) ? Str::upper($builtIn[$provider]->currency()) : null;
            $currency = Str::upper((string) ($section['currency'] ?? $builtInCurrency ?? 'USD'));

            if ($builtInCurrency !== null && $currency !== $builtInCurrency) {
                Log::warning("Spectra: the scraped prices file lists {$provider} prices in {$currency}, but its built-in catalog is in {$builtInCurrency}; using the built-in prices.");

                continue;
            }

            $catalogs[] = new ScrapedPricing($provider, $section['models'], $currency, $builtIn[$provider] ?? null);
        }

        return $catalogs;
    }
}
