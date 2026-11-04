<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Maintenance;

use Illuminate\Http\JsonResponse;
use Spectra\Actions\Maintenance\QueueMaintenanceJob;
use Spectra\Actions\Pricing\ScrapeProviderPrices;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Jobs\ScrapePricingJob;
use Spectra\Support\Maintenance\MaintenanceAccess;

/**
 * POST /api/maintenance/pricing/check: queue a Firecrawl read of the
 * providers' pricing pages. It writes nothing; the changes wait for review.
 */
class CheckPricingController extends BaseApiController
{
    /**
     * Queue the check and answer 202 Accepted, or 422 when it can't run.
     */
    public function __invoke(QueueMaintenanceJob $queue): JsonResponse
    {
        abort_unless(MaintenanceAccess::allowed(), 403);

        if (! ScrapeProviderPrices::configured()) {
            return new JsonResponse(['message' => 'Set SPECTRA_FIRECRAWL_API_KEY to check the pricing pages.'], 422);
        }

        if (ScrapeProviderPrices::defaultCatalogs()['catalogs'] === []) {
            return new JsonResponse(['message' => 'No provider to check: set SPECTRA_SCRAPE_PROVIDERS, or track a request first.'], 422);
        }

        $queue(new ScrapePricingJob);

        return new JsonResponse(['queued' => true], 202);
    }
}
