<?php

declare(strict_types=1);

namespace Spectra\Support;

use Spectra\Support\Macros\WithoutTrackingMacro;
use Spectra\Support\Macros\WithTrackingMacro;

/**
 * Registers the `Http::withAITracking()` / `Http::withoutAITracking()`
 * macros, which are public API, from one place in the service provider.
 */
class HttpMacros
{
    /**
     * Register the withAITracking and withoutAITracking macros on the Http client.
     */
    public static function register(): void
    {
        WithTrackingMacro::register();

        WithoutTrackingMacro::register();
    }
}
