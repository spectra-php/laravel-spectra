<?php

declare(strict_types=1);

namespace Spectra\Actions\Pricing;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Spectra\Pricing\ProviderPricing;
use Spectra\Support\Pricing\Feeds\LiteLlmFeed;
use Spectra\Support\Pricing\Feeds\ModelsDevPrices;
use Spectra\Support\Pricing\Feeds\PricingFileSync;
use Spectra\Support\Pricing\Feeds\PricingSync;
use Spectra\Support\Pricing\PricingCatalog;
use Spectra\Support\Pricing\PricingFile;

/**
 * Reads LiteLLM's cost map and saves the prices that differ from the bundled
 * catalog to a pricing file. Used by spectra:pricing-sync.
 *
 * Written to the bundled catalog itself (how the catalog is maintained), it
 * changes that file's models in place. Written to any other file, it saves
 * only the changed and new models of the providers it synced, so the file
 * stays a short overlay over the bundled catalog.
 */
class SyncPricingFile
{
    /**
     * Create a new sync pricing file action instance.
     */
    public function __construct(
        private readonly PricingSync $sync,
    ) {}

    /**
     * Sync the file with the feed.
     *
     * @param  list<string>  $providers  The providers to sync; empty for every provider the feed covers.
     * @param  bool  $decline  Record every change the feed would make as declined, and write no prices.
     * @param  string|null  $declinedPath  The declined changes file; null for the one bundled with the package.
     *
     * @throws RuntimeException When the file can't be read or the feed can't be loaded.
     */
    public function __invoke(PricingFile $file, array $providers = [], bool $dryRun = false, bool $decline = false, ?string $declinedPath = null): PricingFileSync
    {
        $updatesBundled = PricingCatalog::isBundledFile($file);
        $fileExisted = $file->exists();
        $fileContents = $file->read();

        $catalogs = Arr::where(PricingCatalog::bundled(), fn (ProviderPricing $pricing, string $provider) => LiteLlmFeed::covers($provider)
            && ($providers === [] || in_array($provider, $providers, true)));

        $declinedPath ??= dirname(__DIR__, 3).'/resources/pricing/declined.json';
        $previouslyDeclined = $this->readDeclined($declinedPath);
        $declined = $decline ? [] : $previouslyDeclined;

        $feed = LiteLlmFeed::fetch();
        $notices = [];

        try {
            $modelsDev = ModelsDevPrices::fetch();
        } catch (RuntimeException $e) {
            $modelsDev = null;
            $notices[] = $e->getMessage();
        }

        $before = $fileContents['providers'];
        $results = [];
        $nowDeclined = [];
        $usedDeclines = [];

        foreach ($catalogs as $provider => $pricing) {
            $providerSync = $this->sync->sync($pricing, $feed->rows($pricing), $declined, $modelsDev);
            $result = $providerSync->result;
            $results[$provider] = $providerSync;
            $usedDeclines = [...$usedDeclines, ...$providerSync->usedDeclines];
            $nowDeclined = [...$nowDeclined, ...$this->sync->declineAll($provider, $result)];

            $section = is_array($fileContents['providers'][$provider] ?? null) ? $fileContents['providers'][$provider] : [];

            if ($updatesBundled) {
                foreach ($result->models as $id => $entry) {
                    $fileContents['providers'][$provider]['models'][$id] = $entry;
                }

                continue;
            }

            if ($result->models === []) {
                unset($fileContents['providers'][$provider]);

                continue;
            }

            $fileContents['providers'][$provider] = [
                ...Arr::only($section, ['notes']),
                'currency' => Str::upper($pricing->currency()),
                'models' => $result->models,
            ];
        }

        // Loose on purpose: a price read from the file as 90 is the same price as a synced 90.0.
        $changed = $fileContents['providers'] != $before;
        $written = false;
        $declinedCount = null;

        if ($decline) {
            // Providers that were not synced keep what they had declined.
            $nowDeclined += Arr::where($previouslyDeclined, fn (mixed $value, string $key) => ! isset($catalogs[Str::before($key, '|')]));
            ksort($nowDeclined);
            File::put($declinedPath, json_encode($nowDeclined, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n");
            $declinedCount = count($nowDeclined);
        } elseif ($changed && ! $dryRun) {
            ksort($fileContents['providers']);
            $file->write(['generated_at' => now()->toIso8601String(), 'providers' => $fileContents['providers']]);
            PricingCatalog::flushCache();
            $written = true;
        }

        return new PricingFileSync(
            path: $file->path(),
            updatesBundled: $updatesBundled,
            fileExisted: $fileExisted,
            providers: $results,
            notices: $notices,
            changed: $changed,
            written: $written,
            declined: $declinedCount,
            staleDeclines: count(array_diff_key($previouslyDeclined, array_flip($usedDeclines))),
            models: $this->countModels($fileContents['providers']),
        );
    }

    /**
     * Count the models a file's provider sections hold.
     *
     * @param  array<array-key, mixed>  $providers
     */
    protected function countModels(array $providers): int
    {
        $count = 0;

        foreach ($providers as $section) {
            if (is_array($section) && is_array($section['models'] ?? null)) {
                $count += count($section['models']);
            }
        }

        return $count;
    }

    /**
     * Read the declined changes: key => the feed price that was declined, in
     * cents (null for a whole model). Empty when the file isn't there.
     *
     * @return array<string, float|null>
     */
    protected function readDeclined(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $declined = json_decode(File::get($path), true);

        return collect(is_array($declined) ? $declined : [])
            ->mapWithKeys(fn (mixed $value, int|string $key) => [(string) $key => is_numeric($value) ? (float) $value : null])
            ->all();
    }
}
