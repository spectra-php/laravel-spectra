<?php

declare(strict_types=1);

namespace Spectra\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Spectra\Actions\Pricing\ScrapeProviderPrices;
use Spectra\Support\Maintenance\MaintenanceHistory;
use Spectra\Support\Pricing\Scraping\PricingReview;
use Spectra\Support\Pricing\Scraping\ScrapedPriceMapper;
use Throwable;

/**
 * Reads the pricing pages of the providers spectra:pricing-scrape would
 * scrape by default, and keeps the price changes for review on the
 * dashboard. It writes nothing to the scraped prices file: the reviewer
 * picks the models to apply. Only one is queued at a time.
 */
class ScrapePricingJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Firecrawl can take minutes per page, so the job may run long.
     */
    public int $timeout = 1800;

    /**
     * A retry would spend the Firecrawl credits again.
     */
    public int $tries = 1;

    /**
     * How long, in seconds, a second check can't be queued while this one is.
     */
    public int $uniqueFor = 3600;

    /**
     * Scrape each default provider and save the results for review.
     */
    public function handle(ScrapeProviderPrices $scrapePrices, ScrapedPriceMapper $mapper): void
    {
        $providers = [];

        foreach (ScrapeProviderPrices::defaultCatalogs()['catalogs'] as $provider => $pricing) {
            try {
                $providers[$provider] = PricingReview::fromScrape($scrapePrices($pricing), $mapper);
            } catch (RuntimeException $e) {
                $providers[$provider] = PricingReview::failed($e->getMessage());
            }
        }

        PricingReview::save($providers);

        MaintenanceHistory::record(MaintenanceHistory::PRICING_SCRAPE, [
            'providers' => count($providers),
            'changes' => array_sum(array_map(fn (array $result) => count($result['changes']), $providers)),
        ]);
    }

    /**
     * Stop the dashboard showing the check as queued once it failed.
     */
    public function failed(?Throwable $exception): void
    {
        MaintenanceHistory::clearPending(MaintenanceHistory::PRICING_SCRAPE);
    }
}
