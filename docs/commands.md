# Artisan Commands

Spectra provides Artisan commands for installation, data retention, statistics maintenance, pausing recording and pricing updates. The dashboard's [Maintenance screen](/dashboard#maintenance) runs the same prune and rebuild; prices are checked and written only with `spectra:pricing-scrape`.

<a name="spectra-install"></a>
## `spectra:install`

An interactive installer that publishes the config file and database migrations, and can run the migrations for you. Use it to set up Spectra after installing the Composer package, and to add new migrations after upgrading.

```shell
php artisan spectra:install
```

The installer runs these steps in order:

1. **Publishes configuration.** Copies `config/spectra.php` to your application's config directory (an existing file is kept).
2. **Publishes missing migrations.** Copies only the Spectra migrations your `database/migrations/` folder doesn't have yet, so it's safe to run again after an upgrade.
3. **Runs migrations** (optional). If it published something, it asks whether to run the migrations. Run without a terminal, with `--no-interaction` or from a Composer script, it never runs them and prints the `php artisan migrate` to run instead.

<a name="spectra-install-composer"></a>
### Publish New Migrations on `composer update` {#spectra-install-composer}

To pick up the migrations of a new Spectra version without remembering the installer, add it to the `post-update-cmd` scripts in your application's `composer.json`:

```json
"scripts": {
    "post-update-cmd": [
        "@php artisan vendor:publish --tag=laravel-assets --ansi --force",
        "@php artisan spectra:install --no-interaction"
    ]
}
```

Composer runs `post-update-cmd` after `composer update`, which you usually run on your own machine to upgrade, and not after the `composer install` of a deploy. The new migrations land in `database/migrations/`; commit them, and the `php artisan migrate` your deploy already runs applies them. The installer doesn't run them itself here, so a Composer script never changes a database.

A package can't add this for you: Composer only runs the scripts in your application's own `composer.json`.

On a fresh install you can also publish the files yourself. Don't use `vendor:publish` for migrations when upgrading, though: it copies the older migrations again under a new date.

```shell
php artisan vendor:publish --tag=spectra-config
php artisan vendor:publish --tag=spectra-migrations
php artisan migrate
```

<a name="spectra-prune"></a>
## `spectra:prune`

Deletes old request records, with their spans, scores, review queue items and media files, so storage doesn't keep growing. Pruning can't be undone. Make sure your retention period meets your audit and compliance requirements before you schedule this command.

```shell
php artisan spectra:prune [--hours=24] [--stats-days=] [--chunk=1000]
```

<a name="spectra-prune-options"></a>
### Options {#spectra-prune-options}

| Option | Default | Description |
| --- | --- | --- |
| `--hours=` | `24` | Keep requests from the last N hours. Older requests are deleted. |
| `--stats-days=` | (not set) | Also delete daily stats older than N days. Without this option, daily stats are kept. |
| `--chunk=` | `1000` | How many rows to delete per query |
| `--payload-hours=` | (not set) | Also delete request and response bodies older than N hours, keeping the calls themselves. See [Keep Bodies for Less Time](/production#keep-bodies-for-less-time). |
| `--pause=` | `0` | Milliseconds to wait between chunks, to leave more room for live writes on a busy database |

The command deletes rows from `spectra_requests` whose `created_at` is older than the cutoff, rows from `spectra_spans` that started before it, and rows from `spectra_scores` created before it. It works in chunks, so a large table is not locked by one huge query. Their tags in `spectra_requests_tags` are removed by cascade delete. Media files stored for these requests (see [Media Persistence](/configuration#media-persistence)) are deleted from the media disk too.

Daily stats (`spectra_daily_stats`) are the long-term history behind the dashboard charts, so they are **kept** by default. Pass `--stats-days` to prune them too.

<a name="spectra-prune-examples"></a>
### Examples {#spectra-prune-examples}

```shell
# Delete requests older than 24 hours (default)
php artisan spectra:prune

# Keep 7 days of requests
php artisan spectra:prune --hours=168

# Keep 30 days of requests and one year of daily stats
php artisan spectra:prune --hours=720 --stats-days=365
```

<a name="spectra-prune-scheduling"></a>
### Scheduling {#spectra-prune-scheduling}

Add pruning to your application's scheduler to apply your retention period automatically:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('spectra:prune --hours=168 --stats-days=365')->daily();
```

<a name="spectra-rebuild-stats"></a>
## `spectra:rebuild-stats`

Rebuilds the `spectra_daily_stats` table from the raw `spectra_requests` records. Run it after data migrations, manual database edits, or bulk imports, or whenever the dashboard stats look out of sync with the requests. An upgrade from 1.x needs one rebuild; see [Rebuild the Daily Stats](/upgrade#rebuild-daily-stats).

The command rebuilds one day at a time: the database adds up the day's requests, and those rows replace the day's stats in one transaction, so a screen never shows a day half rebuilt. Stats are grouped by date, provider, model, model type, and trackable entity. A call saved on a day while that day is being rebuilt can be missed, so rebuild finished days, or rebuild today again afterwards.

::: warning Pruned requests
The stats are rebuilt only from the requests that still exist. If you pruned requests but kept their daily stats, rebuilding that date range deletes those stats. Use `--from` to rebuild only the days whose requests you still have.
:::

```shell
php artisan spectra:rebuild-stats [--from=] [--to=] [--yesterday]
```

<a name="spectra-rebuild-stats-options"></a>
### Options {#spectra-rebuild-stats-options}

| Option | Description |
| --- | --- |
| `--from=` | Start date in `Y-m-d` format (e.g., `2026-01-01`) |
| `--to=` | End date in `Y-m-d` format (e.g., `2026-01-31`) |
| `--yesterday` | Rebuild only yesterday's statistics |

<a name="spectra-rebuild-stats-examples"></a>
### Examples {#spectra-rebuild-stats-examples}

```shell
# Rebuild all stats (prompts for confirmation)
php artisan spectra:rebuild-stats

# Rebuild a specific date range
php artisan spectra:rebuild-stats --from=2026-01-01 --to=2026-01-31

# Rebuild yesterday only (suitable for scheduled runs)
php artisan spectra:rebuild-stats --yesterday
```

<a name="spectra-rebuild-stats-scheduling"></a>
### Scheduling {#spectra-rebuild-stats-scheduling}

To keep the stats fresh, schedule a nightly rebuild:

```php
Schedule::command('spectra:rebuild-stats --yesterday')->dailyAt('01:00');
```

<a name="spectra-pricing-scrape"></a>
## `spectra:pricing-scrape`

Reads the providers' pricing pages with [Firecrawl](https://firecrawl.dev) and saves the prices that differ from the bundled catalog, and the models it doesn't have, to [your pricing file](/pricing#your-own-prices). Needs `SPECTRA_FIRECRAWL_API_KEY` (or `FIRECRAWL_API_KEY`) in your `.env` file, and `SPECTRA_PRICING_PATH` to bill with the saved prices.

```shell
php artisan spectra:pricing-scrape [provider*] [--all] [--url=*] [--path=] [--dry-run]
```

<a name="spectra-pricing-scrape-options"></a>
### Options {#spectra-pricing-scrape-options}

| Option | Description |
| --- | --- |
| `provider*` | The providers to scrape. Default: the providers in `SPECTRA_SCRAPE_PROVIDERS`, else the providers your app has tracked requests for. See [Which Providers Are Scraped](/pricing#scraped-providers). |
| `--all` | Scrape every provider with pricing pages. |
| `--url=*` | Scrape these pages instead of the provider's own. Needs exactly one provider. |
| `--path=` | Write this file instead of `spectra.costs.pricing_path` (default: `resources/spectra/pricing.json`). Pointed at the package's `resources/pricing/catalog.json`, it updates the bundled catalog itself, every model of each provider. |
| `--dry-run` | Print the changes without writing the file. |

<a name="spectra-pricing-scrape-examples"></a>
### Examples {#spectra-pricing-scrape-examples}

```shell
# Scrape the providers your app uses and save the changes
php artisan spectra:pricing-scrape

# Check OpenAI only, without writing anything
php artisan spectra:pricing-scrape openai --dry-run
```

See [Scraping Prices with Firecrawl](/pricing#scraping-prices) for how rows are matched and checked.

<a name="spectra-pause"></a>
## `spectra:pause` and `spectra:resume` {#spectra-pause}

Pause recording without a deploy, for example during a load test or an incident:

```shell
php artisan spectra:pause
php artisan spectra:resume
```

While paused, AI calls keep working, but Spectra stores no request, span, score or media file. Their cost is not recorded, so it doesn't count toward [budgets](/budgets). The flag is kept in your default cache store, so every server and queue worker sees it when they share that store; `cache:clear` resumes recording. Each request or job reads the flag once when it first records something, and a long-running process reads it again every 5 seconds, so a pause reaches work already running within a few seconds. The dashboard's Maintenance screen has the same switch.

<a name="spectra-check-alerts"></a>
## `spectra:check-alerts`

Checks the [alert rules](/alerts) in `spectra.alerts.rules` against the stored calls, prints each rule's value now, and notifies when a rule starts firing, keeps firing past its cooldown, or recovers. Schedule it every few minutes:

```php
Schedule::command('spectra:check-alerts')->everyFiveMinutes();
```

<a name="spectra-group-errors"></a>
## `spectra:group-errors`

Groups the failed calls saved before the [error fingerprint migration](/migrations#error-fingerprint-migration) ran, so the dashboard's [Errors screen](/dashboard#errors) shows them too. New failures are grouped as they are saved, so run it once after upgrading:

```shell
php artisan spectra:group-errors
```

<a name="spectra-eval"></a>
## `spectra:eval`

Runs an [evaluation](/evaluations) over its [dataset](/datasets) and prints each scorer's result. Pass the class, or its name in `App\Evals`:

```shell
php artisan spectra:eval SupportAnswerEval [--limit=10]
```

The run, with every example's output and scores, shows on the dashboard's Evals screen.

<a name="command-summary"></a>
## Command Summary

| Command | Purpose | Typical Usage |
| --- | --- | --- |
| `spectra:install` | Interactive setup; publishes missing migrations | After `composer require` and after upgrading |
| `spectra:prune --hours=168` | Delete old request data, review queue items and media | Scheduled daily for retention management |
| `spectra:rebuild-stats --yesterday` | Rebuild aggregated statistics | Scheduled daily, after data corrections, or once after upgrading |
| `spectra:pricing-scrape` | Save new provider prices to your pricing file | Before a release picks them up; review and commit the file |
| `spectra:pause` / `spectra:resume` | Stop and restart storing requests | During load tests or incidents |
| `spectra:check-alerts` | Check the alert rules and notify | Scheduled every few minutes |
| `spectra:eval SupportAnswerEval` | Run an evaluation over its dataset | After changing a prompt or model, or in CI |
| `spectra:group-errors` | Group failed calls saved before the error fingerprint migration | Once after upgrading |
