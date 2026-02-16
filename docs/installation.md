# Installation

## Requirements

- PHP 8.2 or higher
- Laravel 11.x or 12.x
- MySQL 8.0+ or PostgreSQL 13+

## Install the Package

You may install Spectra into your project using the Composer package manager:

```shell
composer require spectra-php/laravel-spectra
```

::: tip TTS requirement
If you use **Text-to-Speech (TTS)** models, install `james-heinrich/getid3` as well. Spectra uses it to extract audio duration from binary TTS responses for duration-based metrics and cost calculation.

```shell
composer require james-heinrich/getid3
```
:::

After installing Spectra, publish its assets and run the database migrations using the `spectra:install` Artisan command:

```shell
php artisan spectra:install
```

The installer publishes the configuration file to `config/spectra.php` and the schema migrations to `database/migrations/`, then prompts you to run the migrations.

If you prefer to run each step manually instead of using the interactive installer:

```shell
php artisan vendor:publish --tag=spectra-config
php artisan vendor:publish --tag=spectra-migrations
php artisan migrate
```

## Configuration

After publishing Spectra's assets, its primary configuration file will be located at `config/spectra.php`. This configuration file allows you to control every aspect of Spectra's behavior — storage settings, persistence mode, dashboard options, cost calculation, budget enforcement, and integration settings. Each option includes a description of its purpose, so be sure to explore the file thoroughly. For a complete reference, see [Configuration](/configuration).

The most important setting to be aware of is the master switch:

```php
'enabled' => env('SPECTRA_ENABLED', true),
```

When set to `false`, Spectra does nothing — no interception, no tracking, no database writes. This allows you to disable observability entirely in specific environments without removing the package.

## Dashboard Authorization

The Spectra dashboard may be accessed via the `/spectra` route. By default, you will only be able to access this dashboard in the `local` environment. The dashboard is protected by a Laravel gate called `viewSpectra`, which controls access in **non-local** environments. You are free to modify this gate as needed to restrict access to your Spectra installation:

```php
use Illuminate\Support\Facades\Gate;

// In your AuthServiceProvider or AppServiceProvider:
Gate::define('viewSpectra', function ($user) {
    return in_array($user->email, [
        'admin@example.com',
    ]);
});
```

You may also add middleware to the dashboard routes via the configuration file. This is useful for enforcing authentication or other access controls at the route level:

```php
'dashboard' => [
    'middleware' => ['web', 'auth'],
],
```

The dashboard path is configurable via the `SPECTRA_PATH` environment variable or the `dashboard.path` configuration option:

```php
'dashboard' => [
    'path' => env('SPECTRA_PATH', 'spectra'),
],
```

## Layout Modes

The `dashboard.layout` configuration option controls which model types are displayed in the dashboard. This is useful if your application only uses a subset of model types and you want a focused view:

| Layout | Shows |
| --- | --- |
| `full` | All model types with a type distribution chart |
| `text` | Text completions only |
| `embedding` | Embeddings only |
| `image` | Image generation only |
| `video` | Video generation only |
| `audio` | TTS and STT metrics |

## Separate Database

For high-volume applications, you may want Spectra to use its own database connection. This isolates observability writes from your application database and prevents Spectra from competing with your application queries for connection pool resources. To configure a separate connection, set the `storage.connection` option:

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
