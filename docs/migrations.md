# Migrations

Spectra uses standard Laravel migrations to create its tables for requests, budgets, daily statistics, and tags. Pricing lives in [PHP classes](/pricing) and needs no database tables.

<a name="schema-migrations"></a>
## Schema Migrations

Publish the schema migrations to your application before you run `php artisan migrate`. They create these tables:

| Table | Purpose |
| --- | --- |
| `spectra_requests` | Stores every tracked AI request with provider, model, tokens, cost, and metadata |
| `spectra_budgets` | Budget limits and thresholds for spending enforcement |
| `spectra_daily_stats` | Aggregated daily statistics per provider/model combination |
| `spectra_tags` | Tag name lookup table for request categorization |
| `spectra_requests_tags` | Pivot table linking requests to tags |

Spectra ships these migration files:

| Migration | What it does |
| --- | --- |
| `create_spectra_entries` | Creates the five tables above |
| `change_trackable_id_to_string_on_spectra_tables` | Stores trackable ids as strings, so UUID, ULID, and integer keys all work |
| `add_v2_tracking_columns_to_spectra_tables` | Adds the Spectra 2.0 columns (see below) |
| `add_spans_to_spectra_tables` | Creates `spectra_spans` and adds `span_id` to `spectra_requests`, for [spans](/usage#spans) |
| `add_trace_timing_columns_to_spectra_tables` | Adds `started_at` (to the millisecond) and `origin` for [traces](/usage#traces), with an index on `trace_id` and `started_at` |
| `create_spectra_scores_table` | Creates `spectra_scores`, for [scores](/scores) (see [below](#scores-migration)) |
| `add_prompt_columns_to_spectra_requests_table` | Adds `prompt_name` and `prompt_version` to `spectra_requests`, for [prompt versions](/prompts) (see [below](#prompt-columns-migration)) |
| `add_error_fingerprint_to_spectra_requests_table` | Adds `error_fingerprint` to `spectra_requests`, for the [Errors screen](/dashboard#errors) (see [below](#error-fingerprint-migration)) |
| `create_spectra_annotation_items_table` | Creates `spectra_annotation_items`, for [annotation queues](/annotations) (see [below](#annotation-migration)) |
| `create_spectra_evaluation_tables` | Creates `spectra_datasets`, `spectra_dataset_examples`, `spectra_eval_runs` and `spectra_eval_results`, for [evaluations](/evaluations) (see [below](#evaluation-migration)) |
| `change_budgetable_id_to_string_on_spectra_budgets_table` | Stores budget owner ids as strings, so [budgets](/budgets) work for integer and ULID keys on every database (see [below](#budgetable-id-migration)) |
| `change_trace_id_to_string_on_spectra_requests_table` | Stores trace ids as strings of up to 64 characters, like the spans and scores tables, so `Spectra::withTraceId()` takes any id on every database (see [below](#trace-id-migration)) |
| `create_spectra_dashboards_table` | Creates `spectra_dashboards`, for [dashboards](/dashboards) (see [below](#dashboards-migration)) |
| `add_sections_to_spectra_dashboards_table` | Adds `sections` and `show_messages` to `spectra_dashboards`, for a dashboard's [pages](/dashboards#pages) (see [below](#dashboards-migration)) |
| `drop_show_costs_and_require_trackable_id_on_spectra_dashboards_table` | Makes the Costs page the one switch for a dashboard's costs, and ties every dashboard to one trackable (see [below](#dashboards-migration)) |

<a name="publishing-schema-migrations"></a>
### Publishing Schema Migrations

Publish the schema migrations to your application:

```shell
php artisan vendor:publish --tag=spectra-migrations
```

This copies the migration files to `database/migrations/`. By default, Laravel gives the copies the current date in their file names, so they run after your own migrations.

<a name="v2-migration"></a>
## The 2.0 Migration

Spectra 2.0 adds columns for prompt-cache usage, page counts, error details, the incoming trace's parent span, conversation ids, and request origin.

| Table | New columns |
| --- | --- |
| `spectra_requests` | `cached_tokens`, `cache_write_tokens`, `page_count`, `currency`, `error_type`, `error_message`, `parent_span_id`, `conversation_id` (indexed), `conversation_turn`, `request_source`, `laravel_request_id`, `ip_address`, `user_agent` |
| `spectra_daily_stats` | `total_cached_tokens`, `total_cache_write_tokens`, `total_pages` |

When you upgrade from 1.x, run the installer again:

```shell
php artisan spectra:install
```

It publishes only the migrations your app doesn't have yet (it compares names without the date prefix), then asks if it should run them.

::: warning Don't re-run vendor:publish for migrations
`php artisan vendor:publish --tag=spectra-migrations` gives every published migration today's date, so it can't tell that you already have Spectra's older migrations and copies them again. If you already did this, delete the new copies of `create_spectra_entries` and `change_trackable_id_to_string_on_spectra_tables` before you run `php artisan migrate`.
:::

Run this migration before you deploy 2.0. Without the new columns, Spectra can't save requests. Your app keeps working, because saving errors go to your exception handler instead of being thrown, but the requests are not recorded.

<a name="trace-columns-migration"></a>
## The Trace Columns Migration

`add_trace_timing_columns_to_spectra_tables` adds two columns to `spectra_requests`: `started_at`, the start of each call to the millisecond, and `origin`, the job, Artisan command or route the call came from. Run the installer to publish and run it:

```shell
php artisan spectra:install
```

Until it runs, Spectra keeps recording calls without these two columns and logs a warning once per process; the Traces and Conversations screens and the request page's trace card say the migration is missing instead of listing anything. Rows saved before the migration have no `started_at`; Spectra uses their `created_at` instead.

<a name="spans-migration"></a>
## The Spans Migration

`add_spans_to_spectra_tables` creates the `spectra_spans` table (the `Spectra::span()` blocks and the requests, jobs and commands of each trace) and adds `span_id`, each call's own span id, to `spectra_requests`. Run the installer to publish and run it:

```shell
php artisan spectra:install
```

Until it runs, Spectra keeps recording calls, skips spans and logs a warning once per process. Calls saved before the migration have no `span_id` and show flat on the trace page.

<a name="scores-migration"></a>
## The Scores Migration

`create_spectra_scores_table` creates the `spectra_scores` table for [scores](/scores). Run the installer to publish and run it:

```shell
php artisan spectra:install
```

Until it runs, `Spectra::score()` returns `null` and logs a warning once per process; tracking is not affected.

<a name="prompt-columns-migration"></a>
## The Prompt Columns Migration

`add_prompt_columns_to_spectra_requests_table` adds the `prompt_name` and `prompt_version` columns to `spectra_requests`, for [prompt versions](/prompts). Run the installer to publish and run it:

```shell
php artisan spectra:install
```

Until it runs, Spectra keeps recording calls without the prompt and logs a warning once per process.

<a name="error-fingerprint-migration"></a>
## The Error Fingerprint Migration

`add_error_fingerprint_to_spectra_requests_table` adds the `error_fingerprint` column to `spectra_requests`, which groups similar failures on the [Errors screen](/dashboard#errors). Run the installer to publish and run it, then group the failures saved before it:

```shell
php artisan spectra:install
php artisan spectra:group-errors
```

Until it runs, Spectra keeps recording failed calls without a fingerprint and logs a warning once per process, and the Errors screen shows the failure count without groups.

<a name="annotation-migration"></a>
## The Annotation Migration

`create_spectra_annotation_items_table` creates the `spectra_annotation_items` table for [annotation queues](/annotations). Run the installer to publish and run it:

```shell
php artisan spectra:install
```

Until it runs, no trace is queued for review and a warning is logged once per process; tracking is not affected.

<a name="evaluation-migration"></a>
## The Evaluation Migration

`create_spectra_evaluation_tables` creates the `spectra_datasets`, `spectra_dataset_examples`, `spectra_eval_runs` and `spectra_eval_results` tables for [evaluations](/evaluations). Run the installer to publish and run it:

```shell
php artisan spectra:install
```

Until it runs, the Evals screen asks you to, and `Spectra::dataset()` and `spectra:eval` fail with a database error.

<a name="dashboards-migration"></a>
## The Dashboards Migration

`create_spectra_dashboards_table` creates the `spectra_dashboards` table for [dashboards](/dashboards), `add_sections_to_spectra_dashboards_table` adds its `sections` and `show_messages` columns, and `drop_show_costs_and_require_trackable_id_on_spectra_dashboards_table` removes `show_costs` and requires `trackable_id`. Run the installer to publish and run them:

```shell
php artisan spectra:install
```

Until it runs, the Dashboards screen asks you to, and `Spectra::dashboard()` fails with a database error.

<a name="budgetable-id-migration"></a>
## The Budgetable Id Migration

`change_budgetable_id_to_string_on_spectra_budgets_table` changes `spectra_budgets.budgetable_id` from a UUID column to a string, as `change_trackable_id_to_string_on_spectra_tables` did for requests and daily stats. Run the installer to publish and run it:

```shell
php artisan spectra:install
```

Until it runs, a budget for a model with an integer or ULID key can't be saved on PostgreSQL (the insert fails with `invalid input syntax for type uuid`), and the budget middleware lets that model's calls through unchecked. MySQL and SQLite accept the ids either way, so the migration changes nothing visible there.

<a name="trace-id-migration"></a>
## The Trace Id Migration

`change_trace_id_to_string_on_spectra_requests_table` changes `spectra_requests.trace_id` from a UUID column to a string of up to 64 characters, the type the spans, scores, review queue and evaluation tables already use. Run the installer to publish and run it:

```shell
php artisan spectra:install
```

Until it runs, a call made under a trace id that isn't a UUID (`Spectra::withTraceId('order-12345')`) isn't saved on PostgreSQL, and the scores of a conversation's or a prompt's traces are missing from those screens there (the query compares the two column types and fails). MySQL and SQLite accept the ids either way.

<a name="custom-database-connection"></a>
### Custom Database Connection

All Spectra migrations use the `spectra.storage.connection` config value. To store Spectra data in a separate database, set the connection in `config/spectra.php`:

```php
'storage' => [
    'connection' => 'spectra',
],
```

Then run migrations on that connection:

```shell
php artisan migrate --database=spectra
```

`php artisan migrate:fresh` drops only the tables of your default connection, so the Spectra database keeps its tables while its migrations show as pending again. Spectra's migrations check for each table and column first and skip what is already there, so running them again is safe.
