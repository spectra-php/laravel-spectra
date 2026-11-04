<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Maintenance;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Spectra\Actions\Pricing\ApplyScrapedPrices;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Support\Maintenance\MaintenanceAccess;

/**
 * POST /api/maintenance/pricing/apply: write the chosen models of the last
 * check to the scraped prices file. Body: `{"models": {"openai": ["gpt-4o"]}}`.
 */
class ApplyPricingController extends BaseApiController
{
    /**
     * Apply the chosen models and answer with how many were written.
     */
    public function __invoke(Request $request, ApplyScrapedPrices $apply): JsonResponse
    {
        abort_unless(MaintenanceAccess::allowed(), 403);

        $validated = $request->validate([
            'models' => ['required', 'array', 'min:1'],
            'models.*' => ['array'],
            'models.*.*' => ['string'],
        ]);

        try {
            $applied = $apply(array_map(array_values(...), $validated['models']));
        } catch (RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        return new JsonResponse(['applied' => $applied]);
    }
}
