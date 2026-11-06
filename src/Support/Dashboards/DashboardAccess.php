<?php

declare(strict_types=1);

namespace Spectra\Support\Dashboards;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * Who may design shared dashboards: the `dashboards.enabled` option must
 * be on and the `manageSpectra` gate must allow the user. Viewing the dashboard
 * (`viewSpectra`) is not enough, because a shared dashboard gives people outside
 * it access to data.
 */
class DashboardAccess
{
    /**
     * Determine whether shared dashboards are turned on in the config.
     */
    public static function enabled(): bool
    {
        return (bool) config('spectra.dashboards.enabled', false);
    }

    /**
     * Determine whether the dashboards migration has run.
     */
    public static function migrated(): bool
    {
        return Schema::connection(config('spectra.storage.connection'))->hasTable('spectra_dashboards');
    }

    /**
     * Determine whether the current user may design shared dashboards.
     */
    public static function allowed(): bool
    {
        return static::enabled() && Gate::allows('manageSpectra');
    }
}
