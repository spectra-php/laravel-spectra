<?php

declare(strict_types=1);

namespace Spectra\Support\Maintenance;

use Illuminate\Support\Facades\Gate;

/**
 * Who may run the dashboard's maintenance actions (prune, rebuild stats,
 * pause recording): the `dashboard.maintenance` option must be on and the
 * `manageSpectra` gate must allow the user. Viewing the dashboard
 * (`viewSpectra`) is not enough, because these actions delete or skip data.
 */
class MaintenanceAccess
{
    /**
     * Determine whether the maintenance actions are turned on in the config.
     */
    public static function enabled(): bool
    {
        return (bool) config('spectra.dashboard.maintenance', true);
    }

    /**
     * Determine whether the current user may run the maintenance actions.
     */
    public static function allowed(): bool
    {
        return static::enabled() && Gate::allows('manageSpectra');
    }
}
