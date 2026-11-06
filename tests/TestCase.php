<?php

declare(strict_types=1);

namespace Spectra\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase as Orchestra;
use Spectra\SpectraServiceProvider;

class TestCase extends Orchestra
{
    /** Every table the migrations and the test setup create, so a leftover table (a queue's jobs table) is recognised. */
    protected const TABLES = [
        'users',
        'spectra_requests',
        'spectra_tags',
        'spectra_requests_tags',
        'spectra_request_payloads',
        'spectra_daily_stats',
        'spectra_budgets',
        'spectra_spans',
        'spectra_scores',
        'spectra_annotation_items',
        'spectra_datasets',
        'spectra_dataset_examples',
        'spectra_eval_runs',
        'spectra_eval_results',
        'spectra_dashboards',
    ];

    /** Columns later migrations add to spectra_requests; a test that drops one leaves the schema incomplete. */
    protected const LATER_COLUMNS = ['started_at', 'origin', 'span_id', 'prompt_name', 'prompt_version', 'error_fingerprint'];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Spectra\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function tearDown(): void
    {
        // Each test boots a fresh app; on a real database server the
        // connections of past tests would otherwise wait for garbage
        // collection and run the server out of clients mid-suite.
        $this->app?->make('db')->disconnect('testing');

        parent::tearDown();
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
        // Rows from a factory are not in the daily stats. Tests of the screens
        // on the daily stats turn this on (RollupParityTest).
        config()->set('spectra.dashboard.read_from_rollups', false);

        $this->configureDatabase($app);

        $schema = $app['db']->connection()->getSchemaBuilder();

        if ($schema->hasTable('spectra_requests')) {
            return;
        }

        $schema->create('users', function ($table) {
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
            __DIR__.'/../database/migrations/2026_11_05_000001_change_budgetable_id_to_string_on_spectra_budgets_table.php',
            __DIR__.'/../database/migrations/2026_11_05_000002_change_trace_id_to_string_on_spectra_requests_table.php',
            __DIR__.'/../database/migrations/2026_11_06_000001_create_spectra_dashboards_table.php',
            __DIR__.'/../database/migrations/2026_11_06_000003_add_sections_to_spectra_dashboards_table.php',
            __DIR__.'/../database/migrations/2026_11_06_000004_drop_show_costs_and_require_trackable_id_on_spectra_dashboards_table.php',
            __DIR__.'/../database/migrations/2026_11_06_000005_add_stat_key_to_spectra_daily_stats_table.php',
            __DIR__.'/../database/migrations/2026_11_06_000006_drop_redundant_indexes_from_spectra_requests_table.php',
            __DIR__.'/../database/migrations/2026_11_06_000007_create_spectra_request_payloads_table.php',
        ] as $path) {
            $migration = include $path;
            $migration->up();
        }
    }

    /**
     * Point the testing connection at the database the environment asks for.
     *
     * The suite runs on Testbench's in-memory SQLite by default, fresh for
     * every test. With SPECTRA_TEST_DB_DRIVER=mysql or pgsql (and the DB_*
     * variables), it runs on that server instead, as CI does for both: the
     * tables are emptied before each test, and dropped and migrated again
     * when a test changed the schema or left a table of its own behind.
     */
    protected function configureDatabase($app): void
    {
        $driver = (string) env('SPECTRA_TEST_DB_DRIVER', 'sqlite');

        if (! in_array($driver, ['mysql', 'pgsql'], true)) {
            return;
        }

        $app['config']->set('database.connections.testing', [
            'driver' => $driver,
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', $driver === 'mysql' ? 3306 : 5432),
            'database' => env('DB_DATABASE', 'spectra'),
            'username' => env('DB_USERNAME', $driver === 'mysql' ? 'root' : 'spectra'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
            'collation' => $driver === 'mysql' ? 'utf8mb4_unicode_ci' : null,
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);

        $connection = $app['db']->connection();
        $schema = $connection->getSchemaBuilder();

        if (! $this->hasCompleteSchema($schema)) {
            $schema->dropAllTables();

            return;
        }

        $schema->withoutForeignKeyConstraints(function () use ($connection) {
            foreach (static::TABLES as $table) {
                $connection->table($table)->truncate();
            }
        });
    }

    /**
     * Determine if the database holds exactly the tables and columns the
     * migrations create, so a test can start from empty tables instead of a
     * fresh schema.
     */
    protected function hasCompleteSchema(Builder $schema): bool
    {
        $tables = collect($schema->getTableListing(schemaQualified: false));

        if ($tables->diff(static::TABLES)->isNotEmpty() || collect(static::TABLES)->diff($tables)->isNotEmpty()) {
            return false;
        }

        return $schema->hasColumns('spectra_daily_stats', ['stat_key', 'latency_samples'])
            && $schema->hasIndex('spectra_daily_stats', 'spectra_daily_stats_stat_key_unique')
            && $schema->hasColumns('spectra_requests', static::LATER_COLUMNS)
            && $schema->hasColumns('spectra_daily_stats', ['total_cached_tokens', 'total_cache_write_tokens', 'total_pages'])
            && $schema->hasTable('spectra_requests_tags');
    }
}
