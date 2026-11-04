<?php

declare(strict_types=1);

namespace Spectra\Contracts;

/**
 * Watchers automatically intercept and track AI requests
 * without requiring manual instrumentation.
 */
interface Watcher
{
    /**
     * Determine if the client library this watcher hooks into is installed.
     */
    public static function isAvailable(): bool;

    /**
     * Hook tracking into the client so its AI requests are recorded.
     */
    public function register(): void;
}
