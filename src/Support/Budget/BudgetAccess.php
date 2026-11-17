<?php

declare(strict_types=1);

namespace Spectra\Support\Budget;

use Illuminate\Support\Facades\Gate;

/**
 * Who may set, change or remove a trackable's budget from the dashboard:
 * the `dashboard.budgets` option must be on and the `manageSpectra` gate
 * must allow the user. Viewing the dashboard (`viewSpectra`) is not enough,
 * because a budget can block a user's AI requests.
 */
class BudgetAccess
{
    /**
     * Determine whether budgets can be edited from the dashboard at all.
     */
    public static function enabled(): bool
    {
        return (bool) config('spectra.dashboard.budgets', true);
    }

    /**
     * Determine whether the current user may edit budgets from the dashboard.
     */
    public static function allowed(): bool
    {
        return static::enabled() && Gate::allows('manageSpectra');
    }
}
