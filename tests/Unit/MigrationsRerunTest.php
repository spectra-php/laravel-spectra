<?php

declare(strict_types=1);

/**
 * Released migrations can run again against tables that already have their
 * change, as happens when a dedicated Spectra database survives the app's
 * migrate:fresh.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function spectraMigration(string $name): object
{
    return include __DIR__."/../../database/migrations/{$name}.php";
}

it('runs the trackable id migration again without changes', function () {
    spectraMigration('2026_06_05_000001_change_trackable_id_to_string_on_spectra_tables')->up();

    expect(Schema::getColumnType('spectra_requests', 'trackable_id'))->toBe('varchar')
        ->and(Schema::getColumnType('spectra_daily_stats', 'trackable_id'))->toBe('varchar');
});

it('skips the trackable id migration when the tables do not exist', function () {
    Schema::drop('spectra_daily_stats');

    spectraMigration('2026_06_05_000001_change_trackable_id_to_string_on_spectra_tables')->up();

    expect(Schema::hasTable('spectra_daily_stats'))->toBeFalse();
});

it('runs the v2 columns migration again without changes', function () {
    spectraMigration('2026_10_15_000001_add_v2_tracking_columns_to_spectra_tables')->up();

    expect(Schema::hasColumns('spectra_requests', ['cached_tokens', 'user_agent']))->toBeTrue()
        ->and(Schema::hasColumn('spectra_daily_stats', 'total_pages'))->toBeTrue();
});

it('adds the daily stats columns a half-finished v2 run left out', function () {
    Schema::table('spectra_daily_stats', function (Blueprint $table) {
        $table->dropColumn(['total_cached_tokens', 'total_cache_write_tokens', 'total_pages']);
    });

    spectraMigration('2026_10_15_000001_add_v2_tracking_columns_to_spectra_tables')->up();

    expect(Schema::hasColumns('spectra_daily_stats', ['total_cached_tokens', 'total_cache_write_tokens', 'total_pages']))->toBeTrue();
});
