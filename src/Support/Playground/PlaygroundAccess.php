<?php

declare(strict_types=1);

namespace Spectra\Support\Playground;

use Illuminate\Support\Facades\Gate;

/**
 * Who may use the dashboard's Playground: the `dashboard.playground.enabled`
 * option must be on and the `manageSpectra` gate must allow the user. Viewing
 * the dashboard (`viewSpectra`) is not enough, because a run spends the app's
 * money and sends a prompt to the provider.
 */
class PlaygroundAccess
{
    /**
     * Determine whether the Playground is turned on in the config.
     */
    public static function enabled(): bool
    {
        return (bool) config('spectra.dashboard.playground.enabled', false);
    }

    /**
     * Determine whether the current user may use the Playground.
     */
    public static function allowed(): bool
    {
        return static::enabled() && Gate::allows('manageSpectra');
    }
}
