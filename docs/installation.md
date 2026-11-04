<a href="https://mayahi.net/services" target="_blank" rel="noopener"><img src="/images/hire-me.svg" alt="Hire me: I'm available for consulting and development work" style="width: 100%; margin-bottom: 24px;" /></a>

# Installation

## Requirements

- PHP 8.2 or higher (PHP 8.3+ required for Laravel 13)
- Laravel 12.x or 13.x
- MySQL 8.0+ or PostgreSQL 13+

## Install the Package

Install Spectra with Composer:

```shell
composer require spectra-php/laravel-spectra
```

::: tip TTS requirement
If you use Text-to-Speech (TTS) models, install `james-heinrich/getid3` as well. Spectra uses it to read the audio duration from binary TTS responses, for duration-based metrics and costs.

```shell
composer require james-heinrich/getid3
```
:::

::: tip Upgrading from 2.x or 1.x?
Read the [Upgrade Guide](/upgrade). Most apps only need to run `php artisan spectra:install` once.
:::

Then publish Spectra's assets and run the database migrations with the `spectra:install` Artisan command:

```shell
php artisan spectra:install
```

The installer publishes the configuration file to `config/spectra.php` and the schema migrations to `database/migrations/`, then asks whether to run the migrations.

To run each step yourself instead of using the interactive installer:

```shell
php artisan vendor:publish --tag=spectra-config
php artisan vendor:publish --tag=spectra-migrations
php artisan migrate
```

## Configuration

Spectra's configuration file is `config/spectra.php`. It controls storage, persistence mode, dashboard options, cost calculation, budget enforcement, and integrations. Each option has a comment that explains it. For the full reference, see [Configuration](/configuration).

The most important setting is the master switch:

```php
'enabled' => env('SPECTRA_ENABLED', true),
```

When it is `false`, Spectra does nothing: no interception, no tracking, no database writes. Use it to turn Spectra off in some environments without removing the package.

## Dashboard Authorization

The Spectra dashboard lives at the `/spectra` route. By default, you can only open it in the `local` environment. In other environments, access is controlled by a Laravel gate called `viewSpectra`. Define the gate to decide who can see your Spectra installation:

```php
use Illuminate\Support\Facades\Gate;

// In your AuthServiceProvider or AppServiceProvider:
Gate::define('viewSpectra', function ($user) {
    return in_array($user->email, [
        'admin@example.com',
    ]);
});
```

The dashboard's [Maintenance screen](/dashboard#maintenance) can delete data, pause recording and change prices, so its actions need a second gate, `manageSpectra`. It also allows only the `local` environment by default, so allowing `viewSpectra` in production does not let viewers run them. Define it for the people who may:

```php
Gate::define('manageSpectra', function ($user) {
    return $user->is_admin;
});
```

You can also add middleware to the dashboard routes in the configuration file, for example to require authentication:

```php
'dashboard' => [
    'middleware' => ['web', 'auth'],
],
```

Change the dashboard path with the `SPECTRA_PATH` environment variable or the `dashboard.path` option:

```php
'dashboard' => [
    'path' => env('SPECTRA_PATH', 'spectra'),
],
```

## Layout Modes

The `dashboard.layout` option controls which model types the dashboard shows. Use it if your application only uses some model types and you want a focused view:

| Layout | Shows |
| --- | --- |
| `full` | All model types with a type distribution chart |
| `text` | Text completions only |
| `embedding` | Embeddings only |
| `image` | Image generation only |
| `video` | Video generation only |
| `audio` | TTS and STT metrics |

## Separate Database

For high-volume applications, you may want Spectra to use its own database connection. This keeps Spectra's writes out of your application database, so they don't compete with your application queries for connections. To use a separate connection, set the `storage.connection` option:

```php
// config/spectra.php
'storage' => [
    'connection' => env('SPECTRA_DB_CONNECTION', 'spectra'),
],
```

Then define the corresponding connection in `config/database.php`:

```php
'connections' => [
    'spectra' => [
        'driver' => 'mysql',
        'host' => env('SPECTRA_DB_HOST', '127.0.0.1'),
        'database' => env('SPECTRA_DB_DATABASE', 'spectra'),
        'username' => env('SPECTRA_DB_USERNAME', 'root'),
        'password' => env('SPECTRA_DB_PASSWORD', ''),
        // ...
    ],
],
```

Run migrations on the separate connection with:

```shell
php artisan migrate --database=spectra
```
