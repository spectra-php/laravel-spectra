<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Spectra\Support\Pricing\PricingCatalog;

/**
 * `.gitattributes` leaves development files out of the package archive. A
 * file the package reads at runtime must not be among them: the dashboard's
 * favicons once were, so installed copies had none.
 */
it('ships every file the package reads at runtime', function () {
    $root = dirname(__DIR__, 2);

    $ignored = collect(File::lines("{$root}/.gitattributes"))
        ->filter(fn (string $line) => Str::endsWith(trim($line), 'export-ignore'))
        ->map(fn (string $line) => ltrim((string) Str::before(trim($line), ' '), '/'))
        ->values();

    $runtimeFiles = [
        'dist/app.js',
        'dist/app.css',
        'dist/viewer.js',
        'resources/images/favicons/favicon.svg',
        'resources/images/favicons/favicon.ico',
        'resources/images/favicons/favicon-96x96.png',
        'resources/images/favicons/apple-touch-icon.png',
        'resources/pricing/declined.json',
        Str::after(PricingCatalog::bundledFile()->path(), "{$root}/"),
    ];

    $leftOut = collect($runtimeFiles)
        ->filter(fn (string $file) => ! File::exists("{$root}/{$file}")
            || $ignored->contains(fn (string $pattern) => $file === $pattern || Str::startsWith($file, "{$pattern}/") || fnmatch($pattern, $file)))
        ->values()
        ->all();

    expect($leftOut)->toBe([]);
});
