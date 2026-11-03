<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * spectra:install publishes only migrations the app doesn't have yet, so it is
 * safe to run again after upgrading (vendor:publish would re-copy them with
 * new dates).
 */
beforeEach(function () {
    $this->databasePath = sys_get_temp_dir().'/spectra-install-'.uniqid();
    File::ensureDirectoryExists($this->databasePath.'/migrations');
    app()->useDatabasePath($this->databasePath);
});

afterEach(fn () => File::deleteDirectory($this->databasePath));

it('publishes every migration on a fresh install', function () {
    $this->artisan('spectra:install')
        ->expectsConfirmation('Would you like to run migrations now?', 'no')
        ->assertSuccessful();

    $packaged = count(File::files(__DIR__.'/../../database/migrations'));

    expect(File::files($this->databasePath.'/migrations'))->toHaveCount($packaged);
});

it('publishes only the missing migrations when run again', function () {
    // An app that installed 1.x: the first two migrations, with their own dates.
    File::put($this->databasePath.'/migrations/2026_04_01_101010_create_spectra_entries.php', '<?php');
    File::put($this->databasePath.'/migrations/2026_06_10_101010_change_trackable_id_to_string_on_spectra_tables.php', '<?php');

    $this->artisan('spectra:install')
        ->expectsConfirmation('Would you like to run migrations now?', 'no')
        ->assertSuccessful();

    $names = collect(File::files($this->databasePath.'/migrations'))
        ->map(fn ($file) => preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $file->getFilename()))
        ->sort()->values()->all();

    expect($names)->toBe([
        'add_trace_timing_columns_to_spectra_tables.php',
        'add_v2_tracking_columns_to_spectra_tables.php',
        'change_trackable_id_to_string_on_spectra_tables.php',
        'create_spectra_entries.php',
    ]);

    // Nothing left to publish the third time.
    $this->artisan('spectra:install')->expectsOutputToContain('already published')->assertSuccessful();
});
