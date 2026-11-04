<?php

declare(strict_types=1);

namespace Spectra\Support\Macros;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Spectra\Support\Tracking\GuzzleMiddleware;

/**
 * `Http::withAITracking($provider, $model, $options)` — track a request to any
 * endpoint of a provider (custom hosts, proxies), with per-request options
 * such as tags or a pricing tier.
 */
class WithTrackingMacro
{
    /**
     * Register the Http::withAITracking() macro that tracks a request for the given provider and model.
     */
    public static function register(): void
    {
        Http::macro('withAITracking', function (string $provider, string $model = 'unknown', array $options = []) {
            /** @var PendingRequest $this */
            return $this
                ->withOptions([GuzzleMiddleware::OPTION_MANUAL => true])
                ->withMiddleware(GuzzleMiddleware::create($provider, $model, $options, manual: true));
        });
    }
}
