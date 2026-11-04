<?php

declare(strict_types=1);

namespace Spectra\Watchers;

use Illuminate\Http\Client\Factory;
use Spectra\Contracts\Watcher;
use Spectra\Support\Tracking\GuzzleMiddleware;
use WeakMap;

/**
 * Watcher for Laravel's HTTP client (the Http facade, Prism, the Laravel AI
 * SDK, and anything else built on it).
 *
 * Registers Spectra's Guzzle middleware as global HTTP client middleware, so
 * every request to a known provider host is tracked — including streamed
 * responses, which are recorded as the application reads them.
 */
class HttpWatcher implements Watcher
{
    /**
     * Factories that already carry the middleware. Static so a factory is
     * never wrapped twice, and safe under Octane: the map holds each factory
     * weakly, so an entry goes when its app instance does, and it stores no
     * request data.
     *
     * @var WeakMap<Factory, true>|null
     */
    protected static ?WeakMap $registered = null;

    public static function isAvailable(): bool
    {
        return class_exists(Factory::class);
    }

    public function register(): void
    {
        $app = app();

        if ($app->resolved(Factory::class)) {
            $this->attach($app->make(Factory::class));

            return;
        }

        $app->afterResolving(Factory::class, fn (Factory $factory) => $this->attach($factory));
    }

    protected function attach(Factory $factory): void
    {
        static::$registered ??= new WeakMap;

        if (isset(static::$registered[$factory])) {
            return;
        }

        static::$registered[$factory] = true;

        $factory->globalMiddleware(GuzzleMiddleware::auto());
    }
}
