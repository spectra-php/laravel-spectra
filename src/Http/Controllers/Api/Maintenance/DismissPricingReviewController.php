<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Maintenance;

use Illuminate\Http\Response;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Support\Maintenance\MaintenanceAccess;
use Spectra\Support\Pricing\Scraping\PricingReview;

/**
 * DELETE /api/maintenance/pricing/review: drop the last check without
 * applying anything.
 */
class DismissPricingReviewController extends BaseApiController
{
    /**
     * Drop the last check.
     */
    public function __invoke(): Response
    {
        abort_unless(MaintenanceAccess::allowed(), 403);

        PricingReview::forget();

        return new Response(status: 204);
    }
}
