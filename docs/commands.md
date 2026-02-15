# Artisan Commands

Spectra provides three Artisan commands for installation, data retention, and statistics maintenance.

<a name="spectra-install"></a>
## `spectra:install`

Interactive installer that publishes the configuration file and database migrations, and optionally runs migrations. This is the recommended way to set up Spectra after installing the Composer package.

```shell
php artisan spectra:install
```

The installer performs the following steps in order:

1. **Publishes configuration** — Copies `config/spectra.php` to your application's config directory.
2. **Publishes schema migrations** — Copies Spectra schema migration files to `database/migrations/`.
3. **Runs migrations** (optional) — Prompts you to run `php artisan migrate` to create the Spectra database tables.

If you prefer manual control, you can skip the installer entirely and publish resources individually:

```shell
php artisan vendor:publish --tag=spectra-config
php artisan vendor:publish --tag=spectra-migrations
php artisan migrate
```

<a name="spectra-prune"></a>
## `spectra:prune`

Deletes old request records and their associated daily statistics to manage storage growth. Pruning is irreversible — make sure your retention period meets your audit and compliance requirements before scheduling this command.

```shell
php artisan spectra:prune [--hours=24]
```

### Options

| Option | Default | Description |
| --- | --- | --- |
| `--hours=` | `24` | Delete records older than this many hours |

The command deletes rows from `spectra_requests` where `created_at` is older than the specified cutoff, corresponding rows from `spectra_daily_stats` where `date` is before the cutoff date, and any orphaned pivot records in `spectra_requests_tags` via cascade delete.

### Examples

```shell
# Delete requests older than 24 hours (default)
php artisan spectra:prune

# Keep 7 days of data
php artisan spectra:prune --hours=168

# Keep 30 days of data
php artisan spectra:prune --hours=720
```

### Scheduling

Add pruning to your application's scheduler for automatic retention management:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('spectra:prune --hours=168')->daily();
```

<a name="spectra-rebuild-stats"></a>
## `spectra:rebuild-stats`

Rebuilds the `spectra_daily_stats` table by re-aggregating data from the raw `spectra_requests` records. Use this command after data migrations, manual database edits, bulk imports, or whenever dashboard statistics appear out of sync with the underlying request data.

The command deletes existing daily stats for the target date range and then re-aggregates from scratch, grouping by date, provider, model, model type, and trackable entity.

```shell
php artisan spectra:rebuild-stats [--from=] [--to=] [--yesterday]
```

### Options

| Option | Description |
| --- | --- |
| `--from=` | Start date in `Y-m-d` format (e.g., `2026-01-01`) |
| `--to=` | End date in `Y-m-d` format (e.g., `2026-01-31`) |
| `--yesterday` | Rebuild only yesterday's statistics |

### Examples

```shell
# Rebuild all stats (prompts for confirmation)
php artisan spectra:rebuild-stats

# Rebuild a specific date range
php artisan spectra:rebuild-stats --from=2026-01-01 --to=2026-01-31

# Rebuild yesterday only (suitable for scheduled runs)
php artisan spectra:rebuild-stats --yesterday
```

### Scheduling

If you want to ensure statistics stay fresh, schedule a nightly rebuild:

```php
Schedule::command('spectra:rebuild-stats --yesterday')->dailyAt('01:00');
```

## Command Summary

| Command | Purpose | Typical Usage |
| --- | --- | --- |
| `spectra:install` | One-time interactive setup | After `composer require` |
| `spectra:prune --hours=168` | Delete old request data | Scheduled daily for retention management |
| `spectra:rebuild-stats --yesterday` | Rebuild aggregated statistics | Scheduled daily or after data corrections |
