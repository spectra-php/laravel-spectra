<?php

namespace Spectra\Watchers;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Spectra\Contracts\Watcher;
use Spectra\Support\ProviderRegistry;
use Spectra\Support\Tracking\GuzzleMiddleware;

/**
 * Watcher that provides Spectra-tracked Guzzle handler stacks.
 *
 * Registers a tracked HandlerStack for each configured provider in the
 * container, allowing any Guzzle client to automatically track AI requests.
 *
 * Usage:
 * ```php
 * // Resolve a tracked handler stack for a specific provider
 * $stack = app('spectra.guzzle.handler.openai');
 * $client = new Client(['handler' => $stack]);
 *
 * // Or use the generic stack that auto-detects the provider
 * $stack = app('spectra.guzzle.handler');
 * ```
 */
class GuzzleWatcher implements Watcher
{
    public static function isAvailable(): bool
    {
        return class_exists(Client::class)
            && class_exists(HandlerStack::class);
    }

    public function register(): void
    {
        $registry = app(ProviderRegistry::class);

        foreach ($registry->slugs() as $slug) {
            app()->singleton("spectra.guzzle.handler.{$slug}", function () use ($slug) {
                $stack = HandlerStack::create();
                $stack->push(GuzzleMiddleware::create($slug), 'spectra');

                return $stack;
            });
        }

        // Auto-detects provider from the request host
        app()->singleton('spectra.guzzle.handler', function () {
            $stack = HandlerStack::create();
            $stack->push(GuzzleMiddleware::create('auto'), 'spectra');

            return $stack;
        });
    }
}
