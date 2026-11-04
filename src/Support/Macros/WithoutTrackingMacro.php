<?php

declare(strict_types=1);

namespace Spectra\Support\Macros;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Spectra\Support\Tracking\GuzzleMiddleware;

/**
 * `Http::withoutAITracking()` — skip tracking for one request.
 */
class WithoutTrackingMacro
{
    /**
     * Register the Http::withoutAITracking() macro that keeps a request out of tracking.
     */
    public static function register(): void
    {
        Http::macro('withoutAITracking', function () {
            /** @var PendingRequest $this */
            return $this->withOptions([GuzzleMiddleware::OPTION_DISABLED => true]);
        });
    }
}
