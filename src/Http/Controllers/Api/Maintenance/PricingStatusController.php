<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Maintenance;

use Spectra\Actions\Pricing\GetPricingStatus;
use Spectra\Data\Responses\PricingStatusResponse;
use Spectra\Http\Controllers\Api\BaseApiController;

/**
 * GET /api/maintenance/pricing: the prices card. Anyone who can view the
 * dashboard sees it; recalculating the stored costs is a separate, gated endpoint.
 */
class PricingStatusController extends BaseApiController
{
    /**
     * Get the pricing status.
     */
    public function __invoke(GetPricingStatus $getPricingStatus): PricingStatusResponse
    {
        return $getPricingStatus();
    }
}
