<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Spectra\Pricing\ProviderPricing;
use Throwable;

/**
 * Builds the pricing catalogs and the lookup over them.
 *
 * Prices come from the catalog bundled with the package. With
 * `spectra.costs.pricing_path` set, the models in that file replace the
 * bundled models with the same id, and the bundled catalog prices
 * everything else. The lookup's index is kept in the cache
 * (`spectra.costs.cache`) and rebuilt when either file changes.
 */
class PricingCatalog
{
    /** Container key tests bind to read the bundled catalog from another file. */
    public const BUNDLED_PATH = 'spectra.pricing.bundled_path';

    protected const CACHE_KEY = 'spectra:pricing';

    /** Bump when the cached index changes shape. */
    protected const CACHE_VERSION = 1;

    /** @var array<string, true> Warnings already logged this process. */
    protected static array $warned = [];

    /**
     * Get the catalog bundled with the package.
     */
    public static function bundledFile(): PricingFile
    {
        $path = app()->bound(self::BUNDLED_PATH) ? app(self::BUNDLED_PATH) : null;

        return is_string($path) && $path !== '' ? new PricingFile($path) : PricingFile::bundled();
    }

    /**
     * Read the bundled catalog from another file (tests). Null restores the package's.
     */
    public static function useBundledFile(?string $path): void
    {
        app()->instance(self::BUNDLED_PATH, $path);

        static::flushCache();
    }

    /**
     * Get the bundled catalogs keyed by provider slug.
     *
     * @return array<string, ProviderPricing>
     */
    public static function bundled(): array
    {
        $catalogs = [];

        foreach (static::bundledFile()->catalogs() as $catalog) {
            $catalogs[$catalog->provider()] = $catalog;
        }

        return $catalogs;
    }

    /**
     * Get the app's own pricing file, or null when `spectra.costs.pricing_path` is not set.
     *
     * A relative path is relative to the app's root. A config published
     * under 3.x still works: with `use_scraped_prices` on, its
     * `scraped_prices_path` is the pricing file.
     */
    public static function userFile(): ?PricingFile
    {
        $path = config('spectra.costs.pricing_path');

        if (blank($path) && config('spectra.costs.use_scraped_prices')) {
            $path = config('spectra.costs.scraped_prices_path') ?: resource_path('spectra/scraped-prices.json');
        }

        if (! is_string($path) || $path === '') {
            return null;
        }

        return new PricingFile(static::absolutePath($path));
    }

    /**
     * Get the catalogs to price requests with, in the order PricingLookup
     * reads them: the bundled catalogs, then the app's file, so the file
     * wins for a model id they share.
     *
     * @return list<ProviderPricing>
     */
    public static function active(): array
    {
        static::warnAboutPricingClasses();

        $bundled = static::bundled();
        $file = static::userFile();

        return [...array_values($bundled), ...($file?->catalogs($bundled) ?? [])];
    }

    /**
     * Get the lookup over the active catalogs, from the cache when it is on
     * and holds an index built from the same files.
     */
    public static function lookup(): PricingLookup
    {
        if (! static::cacheEnabled()) {
            return new PricingLookup(static::active());
        }

        $fingerprint = static::fingerprint();

        try {
            $cached = static::cacheStore()->get(self::CACHE_KEY);

            if (is_array($cached) && ($cached['fingerprint'] ?? null) === $fingerprint && ($cached['lookup'] ?? null) instanceof PricingLookup) {
                return $cached['lookup'];
            }
        } catch (Throwable $e) {
            static::warnOnce('read', "Spectra: couldn't read the pricing cache ({$e->getMessage()}); building the catalog from the pricing files.");

            return new PricingLookup(static::active());
        }

        $lookup = new PricingLookup(static::active());

        try {
            static::cacheStore()->put(self::CACHE_KEY, ['fingerprint' => $fingerprint, 'lookup' => $lookup], static::cacheTtl());
        } catch (Throwable $e) {
            static::warnOnce('write', "Spectra: couldn't write the pricing cache ({$e->getMessage()}); the catalog is built from the pricing files on every request.");
        }

        return $lookup;
    }

    /**
     * Drop the cached index, so the next lookup reads the pricing files again.
     */
    public static function flushCache(): void
    {
        try {
            static::cacheStore()->forget(self::CACHE_KEY);
        } catch (Throwable) {
            // The store is unreachable; the fingerprint check rebuilds the index when it is back.
        }
    }

    /**
     * Determine if the lookup's index is kept in the cache.
     */
    public static function cacheEnabled(): bool
    {
        return (bool) config('spectra.costs.cache.enabled', true);
    }

    /**
     * Get the name of the cache store the index is kept in (null: the default store).
     */
    public static function cacheStoreName(): ?string
    {
        $store = config('spectra.costs.cache.store');

        return is_string($store) && $store !== '' ? $store : null;
    }

    /**
     * Get the cache store the index is kept in.
     */
    protected static function cacheStore(): Repository
    {
        return Cache::store(static::cacheStoreName());
    }

    /**
     * Get how long the index is kept, in seconds (null: until a file changes).
     */
    protected static function cacheTtl(): ?int
    {
        $ttl = (int) config('spectra.costs.cache.ttl', 86400);

        return $ttl > 0 ? $ttl : null;
    }

    /**
     * Identify the pricing files as they are now, so an edit or a package
     * update invalidates the cached index.
     */
    protected static function fingerprint(): string
    {
        $parts = [self::CACHE_VERSION];

        foreach ([static::bundledFile(), static::userFile()] as $file) {
            $path = $file?->path();

            if ($path === null) {
                $parts[] = null;

                continue;
            }

            clearstatcache(true, $path);
            $parts[] = [$path, @filemtime($path), @filesize($path)];
        }

        return sha1((string) json_encode($parts));
    }

    /**
     * Make a pricing path absolute, relative to the app's root.
     */
    protected static function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * Tell an app whose published config still maps pricing classes that they are no longer read.
     */
    protected static function warnAboutPricingClasses(): void
    {
        if (filled(config('spectra.costs.pricing'))) {
            static::warnOnce('classes', 'Spectra: `costs.pricing` in config/spectra.php is no longer read. Prices come from the bundled catalog and `costs.pricing_path`; remove the key (see the upgrade guide).');
        }
    }

    /**
     * Forget which warnings were logged, so a test can expect them again.
     */
    public static function resetWarnings(): void
    {
        static::$warned = [];
    }

    /**
     * Log a warning once per process.
     */
    protected static function warnOnce(string $key, string $message): void
    {
        if (isset(static::$warned[$key])) {
            return;
        }

        static::$warned[$key] = true;

        Log::warning($message);
    }
}
