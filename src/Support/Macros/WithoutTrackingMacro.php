<?php

declare(strict_types=1);

namespace Spectra\Support\Macros;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Spectra\Support\Tracking\GuzzleMiddleware;

/**
 * `Http::withoutAITracking()` — skip tracking for one request.
 */
class WithoutTrackingMacro
{
    /**
     * Register the withoutAITracking() macro that keeps a request out of
     * tracking, on the Http facade and on a pending request.
     */
    public static function register(): void
    {
        Http::macro('withoutAITracking', function () {
            /** @var Factory $this */
            return WithoutTrackingMacro::apply($this);
        });

        PendingRequest::macro('withoutAITracking', function () {
            /** @var PendingRequest $this */
            return WithoutTrackingMacro::apply($this);
        });
    }

    /**
     * Mark the request as one the tracking middleware leaves alone.
     */
    public static function apply(Factory|PendingRequest $request): PendingRequest
    {
        return $request->withOptions([GuzzleMiddleware::OPTION_DISABLED => true]);
    }
}
