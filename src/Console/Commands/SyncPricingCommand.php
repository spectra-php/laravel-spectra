<?php

declare(strict_types=1);

namespace Spectra\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;
use Spectra\Actions\Pricing\SyncPricingFile;
use Spectra\Enums\PricingUnit;
use Spectra\Pricing\ProviderPricing;
use Spectra\Support\Pricing\Feeds\FeedPriceMapper;
use Spectra\Support\Pricing\Feeds\LiteLlmFeed;
use Spectra\Support\Pricing\Feeds\PriceChange;
use Spectra\Support\Pricing\Feeds\ProviderSync;
use Spectra\Support\Pricing\PricingCatalog;
use Spectra\Support\Pricing\PricingFile;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Reads LiteLLM's cost map, a public price feed, and saves the prices that
 * differ from the bundled catalog to the app's pricing file. No key and no
 * page scraping.
 *
 * The feed covers every provider in the catalog except Scaleway, whose
 * prices come with each release. OpenRouter is not in the catalog at all: it
 * reports each request's cost in the response, and Spectra records that.
 *
 * Written to the bundled catalog itself (how the catalog is maintained), it
 * changes that file's models in place. Written to any other file, it saves
 * only the changed and new models, so the file stays a short overlay that
 * keeps following the bundled catalog when the package is updated.
 *
 * `spectra:pricing-scrape`, the command that read pricing pages with
 * Firecrawl before 4.3, is an alias of this one, so scripts that call it
 * keep working. Its --all and --url options are accepted and do nothing.
 */
#[AsCommand(name: 'spectra:pricing-sync', aliases: ['spectra:pricing-scrape'])]
class SyncPricingCommand extends Command
{
    protected $signature = 'spectra:pricing-sync
                            {provider?* : Providers to sync (default: every provider the feed covers)}
                            {--path= : The pricing file to write (default: spectra.costs.pricing_path, else resources/spectra/pricing.json)}
                            {--dry-run : Show the changes without writing the file}
                            {--all : Does nothing: every provider the feed covers is synced unless you name some}
                            {--url=* : Does nothing: prices come from the feed, not from pricing pages}
                            {--declined= : The file of declined feed changes (default: the one bundled with the package)}
                            {--decline : Record every change the feed would make to the bundled catalog now as declined, and write no prices}';

    /** @var list<string> The name the command had while it read pricing pages with Firecrawl. */
    protected $aliases = ['spectra:pricing-scrape'];

    protected $description = 'Save the prices that differ from the bundled catalog to your pricing file, from LiteLLM\'s cost map';

    /**
     * Compare each provider's feed with the bundled catalog and save the prices that differ.
     */
    public function handle(SyncPricingFile $syncPricingFile, FeedPriceMapper $mapper): int
    {
        if ($this->option('url') !== []) {
            $this->components->warn('--url is ignored: prices come from the price feed now, not from pricing pages.');
        }

        $file = $this->targetFile();
        $updatesBundled = PricingCatalog::isBundledFile($file);
        $declines = $this->option('decline') === true;

        if ($declines && ! $updatesBundled) {
            $this->components->error('--decline records what the bundled catalog keeps as it is, so it needs --path to be the bundled catalog.');

            return self::FAILURE;
        }

        try {
            $file->read();
        } catch (RuntimeException $e) {
            $this->components->error("{$e->getMessage()} Fix or delete it, then run the command again.");

            return self::FAILURE;
        }

        $providers = $this->providersToSync();

        if ($providers === null) {
            return self::FAILURE;
        }

        $declinedPath = $this->declinedPath();

        try {
            $result = $syncPricingFile($file, $providers, dryRun: $this->option('dry-run') === true, decline: $declines, declinedPath: $declinedPath);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());
            $this->components->error('The feed could not be loaded. Nothing was written.');

            return self::FAILURE;
        }

        foreach ($result->notices as $notice) {
            $this->components->error($notice);
        }

        foreach ($result->providers as $provider => $providerSync) {
            $this->newLine();
            $this->components->twoColumnDetail("<fg=cyan;options=bold>{$provider}</>", 'LiteLLM');
            $this->report($mapper, $providerSync);
        }

        $this->newLine();

        if ($result->declined !== null) {
            $this->components->info("Declined {$result->declined} changes in {$this->displayPath($declinedPath)}. No price was written.");

            return self::SUCCESS;
        }

        if ($result->staleDeclines > 0 && $updatesBundled && $providers === []) {
            $this->components->warn("{$result->staleDeclines} declined changes no longer apply. Run with --decline to drop them.");
        }

        if (! $result->changed) {
            $this->components->info($updatesBundled || $result->fileExisted
                ? 'The file already has every price the feed has. Nothing was written.'
                : 'The bundled catalog already has every price the feed has. Nothing was written.');

            return self::SUCCESS;
        }

        if (! $result->written) {
            $this->components->info('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        $this->components->info("Saved to {$file->path()}. Review the changes before you deploy them.");
        $this->warnWhenFileIsNotActive($file);

        return self::SUCCESS;
    }

    /**
     * Get the providers to sync: the named ones, or none for every provider
     * the feed covers. Null after reporting one it doesn't cover.
     *
     * @return list<string>|null
     */
    protected function providersToSync(): ?array
    {
        $covered = array_keys(Arr::where(PricingCatalog::bundled(), fn (ProviderPricing $pricing, string $provider) => LiteLlmFeed::covers($provider)));
        $requested = array_values(collect($this->argument('provider'))->filter()->all());

        foreach ($requested as $provider) {
            if (! in_array($provider, $covered, true)) {
                $this->components->error("The price feed doesn't cover '{$provider}'. It covers: ".implode(', ', $covered).'.');

                return null;
            }
        }

        return $requested;
    }

    /**
     * Get the path of the declined changes file: --declined, else the one
     * bundled next to the catalog.
     */
    protected function declinedPath(): string
    {
        $path = $this->option('declined');

        return is_string($path) && filled($path) ? $path : dirname(__DIR__, 3).'/resources/pricing/declined.json';
    }

    /**
     * Print the rows found, the price changes and the skipped rows for one provider.
     */
    protected function report(FeedPriceMapper $mapper, ProviderSync $sync): void
    {
        $result = $sync->result;

        $this->components->twoColumnDetail(
            "{$sync->rows} rows, {$result->matchedModels} catalog models found",
            count($result->changes).' price changes, '.count($result->newModels)." new models, {$sync->declined} declined",
        );

        if ($result->changes !== []) {
            $this->table(
                ['Model', 'Tier', 'Price', 'Ours', 'Feed'],
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
     * Get the file to write: --path, else `spectra.costs.pricing_path`, else
     * the default location of the app's pricing file.
     */
    protected function targetFile(): PricingFile
    {
        $path = $this->option('path');

        if (is_string($path) && $path !== '') {
            $isAbsolute = str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;

            return new PricingFile($isAbsolute ? $path : getcwd().DIRECTORY_SEPARATOR.$path);
        }

        return PricingCatalog::userFile() ?? new PricingFile(resource_path('spectra/pricing.json'));
    }

    /**
     * Warn when Spectra isn't billing with the file that was just written.
     */
    protected function warnWhenFileIsNotActive(PricingFile $file): void
    {
        if (! PricingCatalog::isBundledFile($file) && PricingCatalog::userFile()?->path() !== $file->path()) {
            $this->components->warn("Spectra is billing with the bundled catalog alone. Set SPECTRA_PRICING_PATH={$this->displayPath($file->path())} to bill with this file.");
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
