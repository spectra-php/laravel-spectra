<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Spectra\Pricing\ProviderPricing;

/**
 * Reads and writes a pricing file (JSON): the catalog bundled with Spectra,
 * or the app's own file of prices that replace bundled models.
 *
 * Shape:
 *
 *     {
 *         "generated_at": "2026-10-31T09:00:00+00:00",
 *         "providers": {
 *             "openai": {
 *                 "currency": "USD",
 *                 "sources": ["https://developers.openai.com/api/docs/pricing"],
 *                 "tool_calls": {"web_search_call": 1.0},
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
 * Prices are cents per 1M tokens (or per unit) of the provider's currency.
 * A provider may also carry `notes` (why a price is what it is) and
 * `scraped_at`, which spectra:pricing-scrape keeps and sets.
 */
class PricingFile
{
    /**
     * Create a new pricing file instance for the given path.
     */
    public function __construct(
        protected string $path,
    ) {}

    /**
     * Get the catalog bundled with the package.
     */
    public static function bundled(): self
    {
        return new self(dirname(__DIR__, 3).'/resources/pricing/catalog.json');
    }

    /**
     * Get the path of the pricing file.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Determine if the pricing file exists.
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
            $prices = json_decode(File::get($this->path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("{$this->path} is not valid JSON: {$e->getMessage()}", $e->getCode(), previous: $e);
        }

        if (! is_array($prices) || ! is_array($prices['providers'] ?? null)) {
            throw new RuntimeException("{$this->path} has no \"providers\" object.");
        }

        return $prices;
    }

    /**
     * Write the prices as pretty-printed JSON, creating the folder if needed.
     *
     * @param  array<string, mixed>  $data
     */
    public function write(array $data): void
    {
        File::ensureDirectoryExists(dirname($this->path));
        File::put($this->path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * Get one catalog per provider in the file.
     *
     * `$base` holds the bundled catalogs the file's entries replace models
     * in. A provider whose currency differs from its base catalog is skipped:
     * its models would be converted with the wrong exchange rate. Problems
     * are logged instead of thrown, so cost tracking keeps the base prices.
     *
     * @param  array<string, ProviderPricing>  $base  Provider slug => bundled catalog.
     * @return list<ProviderPricing>
     */
    public function catalogs(array $base = []): array
    {
        if (! $this->exists()) {
            Log::warning("Spectra: the pricing file {$this->path} doesn't exist; the models it would price keep the bundled prices. Run `php artisan spectra:pricing-scrape` to create it.");

            return [];
        }

        try {
            $providers = $this->read()['providers'];
        } catch (RuntimeException $e) {
            Log::warning("Spectra: {$e->getMessage()} The models it would price keep the bundled prices.");

            return [];
        }

        $catalogs = [];

        foreach ($providers as $provider => $section) {
            $provider = (string) $provider;

            if (! is_array($section) || ! is_array($section['models'] ?? null)) {
                Log::warning("Spectra: the pricing file {$this->path} has no models for {$provider}; skipped.");

                continue;
            }

            $catalog = ProviderPricing::fromSection($provider, $section, $base[$provider] ?? null, $this->path);
            $baseCurrency = isset($base[$provider]) ? Str::upper($base[$provider]->currency()) : null;

            if ($baseCurrency !== null && $catalog->currency() !== $baseCurrency) {
                Log::warning("Spectra: the pricing file {$this->path} lists {$provider} prices in {$catalog->currency()}, but the bundled catalog is in {$baseCurrency}; using the bundled prices.");

                continue;
            }

            $catalogs[] = $catalog;
        }

        return $catalogs;
    }
}
