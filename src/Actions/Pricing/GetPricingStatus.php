<?php

declare(strict_types=1);

namespace Spectra\Actions\Pricing;

use Illuminate\Support\Str;
use RuntimeException;
use Spectra\Data\Responses\PricingStatusResponse;
use Spectra\Support\Maintenance\MaintenanceAccess;
use Spectra\Support\Maintenance\MaintenanceHistory;
use Spectra\Support\Pricing\PricingCatalog;
use Spectra\Support\Pricing\Scraping\PricingReview;

/**
 * Everything the prices card shows: Firecrawl and file state, and the last
 * check grouped by model, each model marked when the file already has its
 * scraped prices.
 */
class GetPricingStatus
{
    /**
     * Get the pricing status and the last check for review.
     */
    public function __invoke(): PricingStatusResponse
    {
        [$file, $fileProviders] = $this->file();
        $default = ScrapeProviderPrices::defaultCatalogs();

        return new PricingStatusResponse(
            can_manage: MaintenanceAccess::allowed(),
            firecrawl_configured: ScrapeProviderPrices::configured(),
            uses_scraped_prices: PricingCatalog::usesScrapedPrices(),
            file: $file,
            providers_to_check: ['source' => $default['source'], 'providers' => array_keys($default['catalogs'])],
            review: $this->review($fileProviders),
            pending: MaintenanceHistory::isPending(MaintenanceHistory::PRICING_SCRAPE),
            last_apply: MaintenanceHistory::last(MaintenanceHistory::PRICING_APPLY),
        );
    }

    /**
     * Summarise the scraped prices file, and return its providers for comparison.
     *
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    protected function file(): array
    {
        $file = PricingCatalog::scrapedPricesFile();
        $path = $file->path();
        $summary = [
            'path' => Str::startsWith($path, base_path().DIRECTORY_SEPARATOR) ? Str::after($path, base_path().DIRECTORY_SEPARATOR) : $path,
            'exists' => $file->exists(),
            'generated_at' => null,
            'error' => null,
            'providers' => [],
        ];

        try {
            $data = $file->read();
        } catch (RuntimeException $e) {
            return [[...$summary, 'error' => $e->getMessage()], []];
        }

        $providers = (array) $data['providers'];

        $summary['generated_at'] = is_string($data['generated_at'] ?? null) ? $data['generated_at'] : null;
        $summary['providers'] = collect($providers)->map(fn (mixed $section) => [
            'scraped_at' => is_array($section) ? ($section['scraped_at'] ?? null) : null,
            'models' => is_array($section) && is_array($section['models'] ?? null) ? count($section['models']) : 0,
        ])->all();

        return [$summary, $providers];
    }

    /**
     * The last check grouped by provider and model, or null when there is none.
     *
     * @param  array<string, mixed>  $fileProviders
     * @return array<string, mixed>|null
     */
    protected function review(array $fileProviders): ?array
    {
        $review = PricingReview::get();

        if ($review === null) {
            return null;
        }

        $providers = [];

        foreach ($review['providers'] as $provider => $result) {
            $fileModels = (array) data_get($fileProviders, "{$provider}.models", []);
            $changes = collect((array) ($result['changes'] ?? []))->groupBy('model');

            $providers[] = [
                'provider' => $provider,
                'currency' => $result['currency'] ?? 'USD',
                'sources' => $result['sources'] ?? [],
                'rows' => $result['rows'] ?? 0,
                'matched_models' => $result['matched_models'] ?? 0,
                'skipped' => $result['skipped'] ?? [],
                'error' => $result['error'] ?? null,
                'models' => collect((array) ($result['models'] ?? []))->map(fn (mixed $entry, string $id) => [
                    'id' => $id,
                    'display_name' => is_array($entry) ? ($entry['display_name'] ?? null) : null,
                    'is_new' => in_array($id, (array) ($result['new_models'] ?? []), true),
                    // The file already bills this model at the scraped prices.
                    'applied' => is_array($entry) && is_array($fileModels[$id] ?? null)
                        && ($fileModels[$id]['tiers'] ?? null) == ($entry['tiers'] ?? null),
                    'changes' => $changes->get($id, collect())->values()->all(),
                ])->values()->all(),
            ];
        }

        return ['at' => $review['at'], 'providers' => $providers];
    }
}
