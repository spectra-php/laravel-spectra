<?php

declare(strict_types=1);

namespace Spectra\Actions\Pricing;

use Illuminate\Support\Str;
use RuntimeException;
use Spectra\Data\Responses\PricingStatusResponse;
use Spectra\Support\Pricing\PricingCatalog;

/**
 * What the dashboard's prices card shows: whether requests are billed with
 * the scraped prices file, and what the file holds. Read-only: prices are
 * checked and written with spectra:pricing-scrape where you develop, never
 * from the dashboard, which runs in production.
 */
class GetPricingStatus
{
    /**
     * Get the pricing status.
     */
    public function __invoke(): PricingStatusResponse
    {
        return new PricingStatusResponse(
            uses_scraped_prices: PricingCatalog::usesScrapedPrices(),
            file: $this->file(),
        );
    }

    /**
     * Summarise the scraped prices file.
     *
     * @return array<string, mixed>
     */
    protected function file(): array
    {
        $file = PricingCatalog::scrapedPricesFile();
        $path = $file->path();
        $projectRoot = base_path(DIRECTORY_SEPARATOR);
        $summary = [
            'path' => Str::startsWith($path, $projectRoot) ? Str::after($path, $projectRoot) : $path,
            'exists' => $file->exists(),
            'generated_at' => null,
            'error' => null,
            'providers' => [],
        ];

        try {
            $scrapedPrices = $file->read();
        } catch (RuntimeException $e) {
            return [...$summary, 'error' => $e->getMessage()];
        }

        $summary['generated_at'] = is_string($scrapedPrices['generated_at'] ?? null) ? $scrapedPrices['generated_at'] : null;
        $summary['providers'] = collect((array) $scrapedPrices['providers'])->map(fn (mixed $section) => [
            'scraped_at' => is_array($section) ? ($section['scraped_at'] ?? null) : null,
            'models' => is_array($section) && is_array($section['models'] ?? null) ? count($section['models']) : 0,
        ])->all();

        return $summary;
    }
}
