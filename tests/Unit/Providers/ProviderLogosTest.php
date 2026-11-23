<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Every built-in provider has a logo on the dashboard: a provider added to
 * the config without one would show as a bare name next to the others.
 */
it('has a dashboard logo for every built-in provider', function () {
    $logos = File::get(__DIR__.'/../../../resources/js/components/ProviderLogo.vue');

    $missing = collect(array_keys((array) config('spectra.providers')))
        ->reject(fn (string $slug) => Str::isMatch("/^    {$slug}: \\w+Logo,$/m", $logos))
        ->values()
        ->all();

    expect($missing)->toBe([]);
});
