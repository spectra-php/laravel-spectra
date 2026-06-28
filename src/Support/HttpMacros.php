<?php

declare(strict_types=1);

namespace Spectra\Support;

use Spectra\Support\Macros\WithoutTrackingMacro;
use Spectra\Support\Macros\WithTrackingMacro;

class HttpMacros
{
    public static function register(): void
    {
        WithTrackingMacro::register();

        WithoutTrackingMacro::register();
    }
}
