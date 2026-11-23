<?php

declare(strict_types=1);

namespace Spectra\Tests\Concerns;

use Spectra\Watchers\HttpWatcher;

/**
 * Builds the HTTP watcher and calls its protected methods, for unit tests of
 * the decisions it makes before a request reaches the tracking pipeline.
 */
trait InteractsWithHttpWatcher
{
    protected function makeHttpWatcher(): HttpWatcher
    {
        return new HttpWatcher;
    }

    protected function callHttpWatcherProtected(object $instance, string $method, mixed ...$arguments): mixed
    {
        $callback = \Closure::bind(
            fn (mixed ...$args) => $this->{$method}(...$args),
            $instance,
            $instance::class
        );

        return $callback(...$arguments);
    }
}
