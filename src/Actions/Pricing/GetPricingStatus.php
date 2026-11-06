<?php

declare(strict_types=1);

namespace Spectra\Actions\Pricing;

use Illuminate\Support\Str;
use RuntimeException;
use Spectra\Data\Responses\PricingStatusResponse;
use Spectra\Support\Pricing\PricingCatalog;
use Spectra\Support\Pricing\PricingFile;

/**
 * What the dashboard's prices card shows: the catalog bundled with Spectra,
 * the app's own pricing file (whether it exists and what it holds) and the
 * cache. Read-only: prices are checked and written with
 * spectra:pricing-scrape where you develop, never from the dashboard, which
 * runs in production.
 */
class GetPricingStatus
{
    /**
     * Get the pricing status.
     */
    public function __invoke(): PricingStatusResponse
    {
        $file = PricingCatalog::userFile();

        return new PricingStatusResponse(
            bundled: $this->summarise(PricingCatalog::bundledFile()),
            file: $file !== null ? $this->summarise($file) : null,
            cache: ['enabled' => PricingCatalog::cacheEnabled(), 'store' => PricingCatalog::cacheStoreName()],
        );
    }

    /**
     * Summarise a pricing file: its path, when it was generated, and the models per provider.
     *
     * @return array<string, mixed>
     */
    protected function summarise(PricingFile $file): array
    {
        $path = $file->path();
        $projectRoot = base_path(DIRECTORY_SEPARATOR);
        $summary = [
            'path' => Str::startsWith($path, $projectRoot) ? Str::after($path, $projectRoot) : $path,
            'exists' => $file->exists(),
            'generated_at' => null,
            'error' => null,
            'models' => 0,
            'providers' => [],
        ];

        try {
            $prices = $file->read();
        } catch (RuntimeException $e) {
            return [...$summary, 'error' => $e->getMessage()];
        }

        $summary['generated_at'] = is_string($prices['generated_at'] ?? null) ? $prices['generated_at'] : null;
        $summary['providers'] = collect((array) $prices['providers'])->map(fn (mixed $section) => [
            'scraped_at' => is_array($section) && is_string($section['scraped_at'] ?? null) ? $section['scraped_at'] : null,
            'models' => is_array($section) && is_array($section['models'] ?? null) ? count($section['models']) : 0,
        ])->all();
        $summary['models'] = (int) collect($summary['providers'])->sum('models');

        return $summary;
    }
}
