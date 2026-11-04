<?php

declare(strict_types=1);

namespace Spectra\Actions\Pricing;

use RuntimeException;
use Spectra\Support\Maintenance\MaintenanceHistory;
use Spectra\Support\Pricing\PricingCatalog;
use Spectra\Support\Pricing\Scraping\PricingReview;

/**
 * Writes the chosen models of the last price check to the scraped prices
 * file. The file's other models are kept, so a model the reviewer leaves out
 * keeps the prices it has there (or the built-in ones).
 */
class ApplyScrapedPrices
{
    /**
     * Apply the chosen models and return how many were written.
     *
     * @param  array<string, list<string>>  $selection  Provider slug => model ids from the last check
     *
     * @throws RuntimeException When there is no check to apply or the file can't be read.
     */
    public function __invoke(array $selection): int
    {
        $review = PricingReview::get() ?? throw new RuntimeException('There is no price check to apply. Check the pricing pages again.');

        $file = PricingCatalog::scrapedPricesFile();
        $data = $file->read();
        $applied = 0;

        foreach ($selection as $provider => $modelIds) {
            $result = $review['providers'][$provider] ?? null;

            if (! is_array($result) || ($result['error'] ?? null) !== null) {
                continue;
            }

            $models = array_intersect_key((array) $result['models'], array_flip($modelIds));

            if ($models === []) {
                continue;
            }

            $section = is_array($data['providers'][$provider] ?? null) ? $data['providers'][$provider] : [];

            $data['providers'][$provider] = [
                'currency' => $result['currency'],
                'scraped_at' => $result['scraped_at'],
                'sources' => $result['sources'],
                'models' => [...(array) ($section['models'] ?? []), ...$models],
            ];

            $applied += count($models);
        }

        if ($applied === 0) {
            return 0;
        }

        ksort($data['providers']);
        $file->write(['generated_at' => now()->toIso8601String(), 'providers' => $data['providers']]);

        MaintenanceHistory::record(MaintenanceHistory::PRICING_APPLY, ['models' => $applied]);

        return $applied;
    }
}
