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

    // \r?: a Windows checkout has CRLF line endings, and $ only matches before the \n.
    $missing = collect(array_keys((array) config('spectra.providers')))
        ->reject(fn (string $slug) => Str::isMatch("/^    {$slug}: \\w+Logo,\\r?$/m", $logos))
        ->values()
        ->all();

    expect($missing)->toBe([]);
});
