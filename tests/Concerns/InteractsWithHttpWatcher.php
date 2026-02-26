<?php

namespace Spectra\Tests\Concerns;

use Spectra\Spectra;
use Spectra\Watchers\HttpWatcher;

trait InteractsWithHttpWatcher
{
    protected function makeHttpWatcher(): HttpWatcher
    {
        $manager = \Mockery::mock(Spectra::class);

        return new HttpWatcher($manager);
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
