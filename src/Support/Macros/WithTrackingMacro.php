<?php

declare(strict_types=1);

namespace Spectra\Support\Macros;

use Illuminate\Http\Client\Factory;
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
     * Register the withAITracking() macro that tracks a request for the given
     * provider and model, on the Http facade and on a pending request, so it
     * works after withHeaders(), withToken() and the like too.
     */
    public static function register(): void
    {
        Http::macro('withAITracking', function (string $provider, string $model = 'unknown', array $options = []) {
            /** @var Factory $this */
            return WithTrackingMacro::apply($this, $provider, $model, $options);
        });

        PendingRequest::macro('withAITracking', function (string $provider, string $model = 'unknown', array $options = []) {
            /** @var PendingRequest $this */
            return WithTrackingMacro::apply($this, $provider, $model, $options);
        });
    }

    /**
     * Mark the request as tracked by hand and attach the tracking middleware.
     *
     * @param  array<string, mixed>  $options
     */
    public static function apply(Factory|PendingRequest $request, string $provider, string $model, array $options): PendingRequest
    {
        return $request
            ->withOptions([GuzzleMiddleware::OPTION_MANUAL => true])
            ->withMiddleware(GuzzleMiddleware::create($provider, $model, $options, manual: true));
    }
}
