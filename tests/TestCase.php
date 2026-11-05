<?php

declare(strict_types=1);

namespace Spectra\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase as Orchestra;
use Spectra\SpectraServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Spectra\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app): array
    {
        return [
            SpectraServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
        // Testbench's skeleton has no .env on a fresh install, so the routes that
        // start a session or encrypt a cookie need a key of their own.
        config()->set('app.key', 'base64:'.Str::toBase64(Str::repeat('s', 32)));
        config()->set('cache.default', 'array');

        // Create users table
        $app['db']->connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        // Run schema migrations in order
        foreach ([
            __DIR__.'/../database/migrations/2026_03_01_000001_create_spectra_entries.php',
            __DIR__.'/../database/migrations/2026_06_05_000001_change_trackable_id_to_string_on_spectra_tables.php',
            __DIR__.'/../database/migrations/2026_10_15_000001_add_v2_tracking_columns_to_spectra_tables.php',
            __DIR__.'/../database/migrations/2026_11_03_000001_add_trace_timing_columns_to_spectra_tables.php',
            __DIR__.'/../database/migrations/2026_11_03_000002_add_spans_to_spectra_tables.php',
            __DIR__.'/../database/migrations/2026_11_04_000001_create_spectra_scores_table.php',
            __DIR__.'/../database/migrations/2026_11_04_000002_add_prompt_columns_to_spectra_requests_table.php',
            __DIR__.'/../database/migrations/2026_11_04_000003_add_error_fingerprint_to_spectra_requests_table.php',
            __DIR__.'/../database/migrations/2026_11_04_000004_create_spectra_annotation_items_table.php',
            __DIR__.'/../database/migrations/2026_11_04_000005_create_spectra_evaluation_tables.php',
        ] as $path) {
            $migration = include $path;
            $migration->up();
        }
    }
}
