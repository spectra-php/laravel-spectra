# Artisan Commands

Spectra provides four Artisan commands for installation, data retention, statistics maintenance, and pricing updates.

<a name="spectra-install"></a>
## `spectra:install`

An interactive installer that publishes the config file and database migrations, and can run the migrations for you. Use it to set up Spectra after installing the Composer package, and to add new migrations after upgrading.

```shell
php artisan spectra:install
```

The installer runs these steps in order:

1. **Publishes configuration.** Copies `config/spectra.php` to your application's config directory (an existing file is kept).
2. **Publishes missing migrations.** Copies only the Spectra migrations your `database/migrations/` folder doesn't have yet, so it's safe to run again after an upgrade.
3. **Runs migrations** (optional). If it published something, it asks whether to run the migrations.

On a fresh install you can also publish the files yourself. Don't use `vendor:publish` for migrations when upgrading, though: it copies the older migrations again under a new date.

```shell
php artisan vendor:publish --tag=spectra-config
php artisan vendor:publish --tag=spectra-migrations
php artisan migrate
```

<a name="spectra-prune"></a>
## `spectra:prune`

Deletes old request records and the media files stored for them, so storage doesn't keep growing. Pruning can't be undone. Make sure your retention period meets your audit and compliance requirements before you schedule this command.

```shell
php artisan spectra:prune [--hours=24] [--stats-days=] [--chunk=1000]
```

### Options

| Option | Default | Description |
| --- | --- | --- |
| `--hours=` | `24` | Keep requests from the last N hours. Older requests are deleted. |
| `--stats-days=` | (not set) | Also delete daily stats older than N days. Without this option, daily stats are kept. |
| `--chunk=` | `1000` | How many rows to delete per query |

The command deletes rows from `spectra_requests` whose `created_at` is older than the cutoff, and rows from `spectra_spans` that started before it. It works in chunks, so a large table is not locked by one huge query. Their tags in `spectra_requests_tags` are removed by cascade delete. Media files stored for these requests (see [Media Persistence](/configuration#media-persistence)) are deleted from the media disk too.

Daily stats (`spectra_daily_stats`) are the long-term history behind the dashboard charts, so they are **kept** by default. Pass `--stats-days` to prune them too.

### Examples

```shell
# Delete requests older than 24 hours (default)
php artisan spectra:prune

# Keep 7 days of requests
php artisan spectra:prune --hours=168

# Keep 30 days of requests and one year of daily stats
php artisan spectra:prune --hours=720 --stats-days=365
```

### Scheduling

Add pruning to your application's scheduler to apply your retention period automatically:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('spectra:prune --hours=168 --stats-days=365')->daily();
```

<a name="spectra-rebuild-stats"></a>
## `spectra:rebuild-stats`

Rebuilds the `spectra_daily_stats` table from the raw `spectra_requests` records. Run it after upgrading to Spectra 2.0, after data migrations, manual database edits, or bulk imports, or whenever the dashboard stats look out of sync with the requests.

Run it once after upgrading from 1.x. Spectra 1.x counted each poll of an async job (for example, video generation) in the daily stats, so some totals are too high:

```shell
php artisan spectra:rebuild-stats
```

The command deletes the existing daily stats for the date range, then rebuilds them from scratch, grouped by date, provider, model, model type, and trackable entity.

::: warning Pruned requests
The stats are rebuilt only from the requests that still exist. If you pruned requests but kept their daily stats, rebuilding that date range deletes those stats. Use `--from` to rebuild only the days whose requests you still have.
:::

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

To keep the stats fresh, schedule a nightly rebuild:

```php
Schedule::command('spectra:rebuild-stats --yesterday')->dailyAt('01:00');
```

<a name="spectra-pricing-scrape"></a>
## `spectra:pricing-scrape`

Reads the providers' pricing pages with [Firecrawl](https://firecrawl.dev) and saves the prices that differ from the built-in catalog to the [scraped prices file](/pricing#scraped-prices). Needs `SPECTRA_FIRECRAWL_API_KEY` (or `FIRECRAWL_API_KEY`) in your `.env` file. Set `SPECTRA_USE_SCRAPED_PRICES=true` to bill with the saved prices.

```shell
php artisan spectra:pricing-scrape [provider*] [--all] [--url=*] [--path=] [--dry-run]
```

### Options

| Option | Description |
| --- | --- |
| `provider*` | The providers to scrape. Default: the providers in `SPECTRA_SCRAPE_PROVIDERS`, else the providers your app has tracked requests for. See [Which Providers Are Scraped](/pricing#scraped-providers). |
| `--all` | Scrape every provider with pricing pages. |
| `--url=*` | Scrape these pages instead of the provider's own. Needs exactly one provider. |
| `--path=` | Read and write this file instead of `spectra.costs.scraped_prices_path`. |
| `--dry-run` | Print the changes without writing the file. |

### Examples

```shell
# Scrape the providers your app uses and save the changes
php artisan spectra:pricing-scrape

# Check OpenAI only, without writing anything
php artisan spectra:pricing-scrape openai --dry-run
```

See [Scraping Prices with Firecrawl](/pricing#scraping-prices) for how rows are matched and checked.

## Command Summary

| Command | Purpose | Typical Usage |
| --- | --- | --- |
| `spectra:install` | Interactive setup; publishes missing migrations | After `composer require` and after upgrading |
| `spectra:prune --hours=168` | Delete old request data and media | Scheduled daily for retention management |
| `spectra:rebuild-stats --yesterday` | Rebuild aggregated statistics | Scheduled daily, after data corrections, or once after upgrading |
| `spectra:pricing-scrape` | Save new provider prices to the scraped prices file | Before a release picks them up; review and commit the file |
