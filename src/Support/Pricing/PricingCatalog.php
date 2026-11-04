<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing;

use Spectra\Pricing\ProviderPricing;

/**
 * Builds the pricing catalogs from config.
 *
 * Prices come from the PHP catalogs in `spectra.costs.pricing`. With
 * `spectra.costs.use_scraped_prices` on, the models in the scraped prices
 * file replace the built-in models with the same id, and the built-in
 * catalogs price everything else.
 */
class PricingCatalog
{
    /**
     * Every catalog in `spectra.costs.pricing`, in config order. Two entries
     * may share a provider slug (a second class for extra models); both are
     * kept, as they always were. An entry set to `false` turns that built-in
     * catalog off.
     *
     * @return list<ProviderPricing>
     */
    public static function configured(): array
    {
        return array_values(collect((array) config('spectra.costs.pricing', []))
            ->filter()
            ->map(fn (string $class): ProviderPricing => app()->make($class))
            ->all());
    }

    /**
     * The configured catalogs keyed by provider slug. When two share a slug,
     * the later one wins, as it does for the currency in PricingLookup.
     *
     * @return array<string, ProviderPricing>
     */
    public static function builtIn(): array
    {
        $catalogs = [];

        foreach (static::configured() as $pricing) {
            $catalogs[$pricing->provider()] = $pricing;
        }

        return $catalogs;
    }

    /**
     * The catalogs to price requests with, in the order PricingLookup reads
     * them: later catalogs win for a model id they share.
     *
     * @return list<ProviderPricing>
     */
    public static function active(): array
    {
        $configured = static::configured();

        if (! static::usesScrapedPrices()) {
            return $configured;
        }

        $builtIn = [];
        foreach ($configured as $pricing) {
            $builtIn[$pricing->provider()] = $pricing;
        }

        return [...$configured, ...static::scrapedPricesFile()->catalogs($builtIn)];
    }

    /**
     * Determine if the scraped prices file should replace built-in models.
     */
    public static function usesScrapedPrices(): bool
    {
        return (bool) config('spectra.costs.use_scraped_prices', false);
    }

    /**
     * Get the scraped prices file at the configured path, or the default resource path.
     */
    public static function scrapedPricesFile(): ScrapedPricesFile
    {
        return new ScrapedPricesFile((string) (config('spectra.costs.scraped_prices_path') ?: resource_path('spectra/scraped-prices.json')));
    }
}
