<?php

namespace Spectra\Support\Macros;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class WithoutTrackingMacro
{
    public static function register(): void
    {
        Http::macro('withoutAITracking', function () {
            /** @var PendingRequest $this */
            return $this->withHeaders(['X-Spectra-Manual-Tracking' => '1']);
        });
    }
}
