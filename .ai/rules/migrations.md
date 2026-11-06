---
paths:
  - 'database/migrations/**'
---

# Migrations

## Released migrations never change
Users publish the migrations (`vendor:publish --tag=spectra-migrations`) and have already run every released one, so editing it changes nothing for them. A schema change is a new dated migration, numbered in order within its day and named for what it changes: `YYYY_MM_DD_00000N_{verb}_…`, ending in the table it changes (`_to_spectra_requests_table.php`, `create_spectra_scores_table.php`) or `_spectra_tables.php` when it changes several. The package does not auto-register its migrations (`MigrationsRegistrationTest`).

## Run on the Spectra connection
Every migration overrides `getConnection()` to return `config('spectra.storage.connection')` and builds through `Schema::connection($this->getConnection())`.

## Make every migration safe to re-run
On a dedicated connection the Spectra database survives the app's `migrate:fresh`, so a migration may run against tables that already exist. Guard `up()` with `hasTable()` / `hasColumn()` and return early when the change is already there.

## Work on every driver
Users run SQLite, MySQL, MariaDB and PostgreSQL. Use the schema builder only; no raw driver-specific SQL, no `CHECK` constraints, no Postgres-only types. SQLite can't change some columns in place, so test a column change on SQLite too.

## Index what the dashboard filters and sorts on
Add an index for each column the dashboard or `HasAiUsage` filters, groups or sorts by (`created_at`, `provider`, `model`, the `(trackable_type, trackable_id)` pair). Keep index names short enough for MySQL's 64-character limit.

## Wire up a new migration everywhere
A new migration must also go into the list in `tests/TestCase.php`, the published-migration notes in `docs/migrations.md` and `docs/upgrade.md`, the `@property` docblock of its model, and `CHANGELOG.md` under "Upgrading" (tell users to publish and run it).
