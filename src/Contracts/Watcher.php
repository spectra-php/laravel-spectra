<?php

namespace Spectra\Contracts;

/**
 * Watchers automatically intercept and track AI requests
 * without requiring manual instrumentation.
 */
interface Watcher
{
    public static function isAvailable(): bool;

    public function register(): void;
}
