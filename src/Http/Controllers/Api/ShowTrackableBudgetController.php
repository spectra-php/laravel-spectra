<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Illuminate\Http\Request;
use Spectra\Data\Responses\TrackableBudgetResponse;
use Spectra\Support\Budget\BudgetAccess;
use Spectra\Support\Budget\TrackableBudgets;

/**
 * GET /api/trackables/view/{id}/budget: the budget card on a trackable's
 * page. Anyone who can view the dashboard sees it; `can_manage` says whether
 * they may change it.
 */
class ShowTrackableBudgetController extends BaseApiController
{
    /**
     * Get the trackable's budget with what has been used against each limit.
     */
    public function __invoke(Request $request, string $id, TrackableBudgets $budgets): TrackableBudgetResponse
    {
        $type = $request->input('type');

        abort_unless((bool) $type, 400, 'Missing type parameter.');

        $budget = $budgets->find($type, $id);

        return TrackableBudgetResponse::fromBudget(
            $budget,
            $budget === null ? collect() : $budgets->limits($budget),
            BudgetAccess::allowed(),
        );
    }
}
