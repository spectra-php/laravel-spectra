<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spectra\Support\Budget\BudgetAccess;
use Spectra\Support\Budget\TrackableBudgets;

/**
 * DELETE /api/trackables/view/{id}/budget: remove the trackable's budget, so
 * nothing limits it any more.
 */
class DeleteTrackableBudgetController extends BaseApiController
{
    /**
     * Delete the trackable's budget.
     */
    public function __invoke(Request $request, string $id, TrackableBudgets $budgets): JsonResponse
    {
        abort_unless(BudgetAccess::allowed(), 403);

        $type = $request->input('type');

        abort_unless((bool) $type, 400, 'Missing type parameter.');

        $budgets->remove($type, $id);

        return new JsonResponse(['deleted' => true]);
    }
}
