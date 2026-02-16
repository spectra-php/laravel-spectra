# Migrations

Spectra uses standard Laravel migrations to create the database tables it needs for storing requests, budgets, daily statistics, and tags. Pricing is handled entirely through [PHP classes](/pricing) and does not require any database tables.

<a name="schema-migrations"></a>
## Schema Migrations

Schema migrations create the tables Spectra needs to store requests, budgets, daily statistics, and tags. Publish these migrations to your application before running `php artisan migrate`.

The schema migrations create the following tables:

| Table | Purpose |
| --- | --- |
| `spectra_requests` | Stores every tracked AI request with provider, model, tokens, cost, and metadata |
| `spectra_budgets` | Budget limits and thresholds for spending enforcement |
| `spectra_daily_stats` | Aggregated daily statistics per provider/model combination |
| `spectra_tags` | Tag name lookup table for request categorization |
| `spectra_requests_tags` | Pivot table linking requests to tags |

### Publishing Schema Migrations

If you need to customize the table structure, publish the schema migrations to your application:

```shell
php artisan vendor:publish --tag=spectra-migrations
```

This copies the migration files to `database/migrations/` with their original filenames.

### Custom Database Connection

All Spectra migrations respect the `spectra.storage.connection` config value. To store Spectra data in a separate database, set the connection in `config/spectra.php`:

```php
'storage' => [
    'connection' => 'spectra',
],
```

Then run migrations on that connection:

```shell
php artisan migrate --database=spectra
```
