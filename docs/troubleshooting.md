# Troubleshooting

Common problems with Spectra, and how to fix them.

<a name="no-requests-are-being-tracked"></a>
## No Requests Are Being Tracked

**Symptoms:** The dashboard shows no data and the `spectra_requests` table is empty.

**Checklist:**

1. **Spectra is enabled.** Check that the master switch is on:
   ```bash
   SPECTRA_ENABLED=true
   ```

2. **The watcher is enabled.** Automatic tracking needs the watcher:
   ```bash
   SPECTRA_WATCHER_ENABLED=true
   ```

3. **The provider is registered.** The request hostname must match a provider in `spectra.providers`. Requests to `api.openai.com`, for example, are recognized automatically. Requests to a custom or self-hosted endpoint need a registered [custom provider](/custom-providers).

4. **The endpoint is trackable.** The request path must match a trackable endpoint in the provider's handlers. For example, OpenAI tracks `/v1/chat/completions`, `/v1/embeddings`, and `/v1/images/generations`, but not `/v1/models` or other non-AI endpoints.

5. **Migrations have run.** Make sure the `spectra_requests` table exists:
   ```shell
   php artisan migrate
   ```

6. **You use a supported HTTP client.** Spectra tracks requests sent through Laravel's `Http` facade (which also covers Prism and Laravel AI), the `openai-php/laravel` SDK, and Guzzle clients with Spectra's `GuzzleMiddleware`. Streamed responses through these clients are tracked too. Direct `curl` calls and other HTTP clients are not tracked automatically. For those, use the [Guzzle middleware](/usage#guzzle-middleware), [`Spectra::stream()`](/usage#spectra-stream), or [manual tracking](/usage#manual-tracking).

7. **A stream was not read to the end.** A streamed request is recorded when your code reads the body to the end, closes it, or drops the response. If your code keeps the response object alive without reading it, nothing is recorded yet.

8. **Saving failed.** Spectra never throws saving errors into your app. They go to your exception handler, so check your error log. A common cause is a missing column after an upgrade (see [Migrations](/migrations#v2-migration)).

<a name="requests-tracked-but-cost-is-zero"></a>
## Requests Tracked But Cost Is Zero

**Symptoms:** Requests appear in the dashboard but `total_cost_in_cents` shows `0.000000`.

**Checklist:**

1. **The model is in the catalog.** The pricing catalog needs an entry for each model you use. Prices live in the catalog bundled with Spectra and in [your own pricing file](/pricing#your-own-prices). See [Pricing](/pricing).

2. **The model name matches.** The model id must match a catalog entry: by its exact id, an alias, or the id without a snapshot date or routing suffix. See [How Spectra Finds a Model](/pricing#model-lookup). Compare the `snapshot` and `model` values in your request records with the ids in the catalog.

3. **The pricing tier exists.** If you use a non-standard tier (such as `batch` or `flex`), the model needs a row for that tier; without one it bills at `standard`.

4. **The pricing unit matches the metrics.** The `pricingUnit` on the model definition must match the metrics in the response. For example, a model with `pricingUnit('tokens')` requires non-null `prompt_tokens` and `completion_tokens` values.

5. **Cost tracking is enabled.** Check the configuration:
   ```php
   'costs' => [
       'enabled' => true,
   ],
   ```

6. **Streamed OpenAI Chat Completions.** OpenAI only sends usage in a Chat Completions stream when the request has `'stream_options' => ['include_usage' => true]`. Without it, the request is recorded with zero tokens and zero cost. See [Streaming](/usage#streaming).

<a name="dashboard-shows-empty-data"></a>
## Dashboard Shows Empty Data

**Symptoms:** The dashboard loads but displays no requests or statistics.

**Checklist:**

1. **The dashboard is enabled.** Check that the dashboard is not turned off:
   ```bash
   SPECTRA_DASHBOARD_ENABLED=true
   ```

2. **Request persistence is on.** If `store_requests` is `false`, requests are tracked in memory but not saved to the database:
   ```bash
   SPECTRA_STORE_REQUESTS=true
   ```

3. **The database connection is correct.** If you use a separate database, make sure the dashboard reads from the connection set in `storage.connection`.

4. **The authorization gate passes.** The `viewSpectra` gate must return `true` for your user. In local development this is automatic. In production, define the gate:
   ```php
   Gate::define('viewSpectra', function ($user) {
       return $user->isAdmin();
   });
   ```

5. **The middleware is correct.** If you added `auth` to the dashboard middleware, make sure you are logged in when you open the dashboard.

<a name="queue-persistence-not-working"></a>
## Queue Persistence Not Working

**Symptoms:** Requests are tracked but never appear in the database when using queue mode.

**Checklist:**

1. **A queue worker is running.** Make sure a worker is processing the queue:
   ```shell
   php artisan queue:work
   ```

2. **The queue configuration is correct.** Check that the connection and queue name match your worker:
   ```php
   'queue' => [
       'enabled' => true,
       'connection' => null, // or your queue connection
       'queue' => null,      // or your queue name
   ],
   ```

3. **Check failed jobs.** The `PersistSpectraRequestJob` may be failing silently:
   ```shell
   php artisan queue:failed
   ```

4. **Queue takes priority over after-response.** You can only use one of these modes. When `queue.enabled` is `true`, the `after_response` setting is ignored.

<a name="after-response-mode-not-working"></a>
## After-Response Mode Not Working

**Symptoms:** `after_response` is enabled but requests appear to persist synchronously.

This is expected in two cases:

- **Non-HTTP context.** After-response only works during web requests. Console commands, queue jobs, and scheduled tasks always save synchronously, because there is no HTTP response to wait for.
- **Queue is enabled.** When `queue.enabled` is `true`, it takes priority and `after_response` is ignored.

<a name="budget-middleware-not-enforcing"></a>
## Budget Middleware Not Enforcing

**Symptoms:** Requests proceed even when the user's budget is exceeded.

**Checklist:**

1. **Budgets are enabled** in the configuration:
   ```php
   'budget' => ['enabled' => true],
   ```

2. **A budget record exists and is active** for the user:
   ```php
   $user->aiBudget()->where('is_active', true)->first();
   ```

3. **Middleware is applied correctly** to the route:
   ```php
   Route::middleware(['auth', 'spectra.budget:openai,gpt-4o'])
       ->post('/ai/chat', ChatController::class);
   ```

4. **The hard limit is enabled.** With `hard_limit = false`, requests go through and Spectra only fires events. Set `hard_limit = true` on the budget to block requests once it is exceeded.

<a name="opentelemetry-export-not-appearing"></a>
## OpenTelemetry Export Not Appearing

**Symptoms:** OTEL is enabled but no traces appear in your observability backend.

**Checklist:**

1. **OTEL is enabled** in the configuration:
   ```bash
   SPECTRA_OTEL_ENABLED=true
   ```

2. **The endpoint is reachable.** Test the connection to your OTLP endpoint:
   ```shell
   curl -v http://localhost:4318/v1/traces
   ```

3. **Authentication headers are set.** Most cloud backends require authentication. Check the headers in your configuration.

4. **Export timing.** In after-response mode, traces are exported after the HTTP response. In console or queue contexts, they are exported synchronously. If traces show up from CLI commands but not from web requests, check that your server supports terminable middleware.

5. **The timeout is long enough.** If the OTLP endpoint is remote or slow, increase the timeout:
   ```bash
   SPECTRA_OTEL_TIMEOUT=30
   ```

<a name="stats-look-wrong-after-data-changes"></a>
## Stats Look Wrong After Data Changes

**Symptoms:** Dashboard charts or cost totals don't match the raw request data.

This can also happen after upgrading from 1.x: Spectra 1.x counted each poll of an async job (for example, video generation) in the daily stats.

**Solution:** Rebuild the daily statistics from the raw request records:

```shell
php artisan spectra:rebuild-stats

# Or rebuild a specific date range
php artisan spectra:rebuild-stats --from=2026-01-01 --to=2026-01-31
```

The stats are rebuilt only from requests that still exist. If you pruned old requests but kept their daily stats, rebuild only the days you still have requests for (see [`spectra:rebuild-stats`](/commands#spectra-rebuild-stats)).

<a name="media-files-not-being-saved"></a>
## Media Files Not Being Saved

**Symptoms:** Image or video generation requests are tracked but no media files are downloaded to disk.

**Checklist:**

1. **Media persistence is enabled:**
   ```bash
   SPECTRA_MEDIA_ENABLED=true
   ```

2. **The disk is writable.** Make sure the configured filesystem disk and path exist and your application can write to them.

3. **An API key is available.** Some providers need an API key to download media files. Set the keys in `spectra.api_keys`.

4. **The URLs haven't expired.** Provider media URLs don't last long. If saving is delayed (for example, by a queue with a long delay), the URLs may expire before the download finishes.

<a name="macro-not-available"></a>
## Macro Not Available

**Symptoms:** `Http::withAITracking()` or `Http::withoutAITracking()` throws a "Method not found" error.

**Checklist:**

1. **The service provider is registered.** Spectra's service provider should be auto-discovered. If it isn't, register it yourself:
   ```php
   // config/app.php
   'providers' => [
       Spectra\SpectraServiceProvider::class,
   ],
   ```

2. **Check for conflicts.** Another package may have registered a macro with the same name. Laravel macros are global, and the last registration wins.

The macros are registered whether Spectra is enabled or not: with `SPECTRA_ENABLED=false`, `Http::withAITracking()` works and records nothing. They are also available on a pending request, after `withHeaders()`, `withToken()` and the like.

<a name="high-memory-usage"></a>
## High Memory Usage

**Symptoms:** Application memory spikes when making many AI requests in a single process.

**Solutions:**

- **Enable queue persistence** to move database writes to background workers:
  ```bash
  SPECTRA_QUEUE_ENABLED=true
  ```

- **Disable payload storage** if you don't need request and response bodies:
  ```bash
  SPECTRA_STORE_REQUEST_DATA=false
  SPECTRA_STORE_RESPONSES=false
  ```
  Don't set `SPECTRA_STORE_REQUESTS=false` for this. It stops Spectra from saving request records at all.

- **Schedule pruning** to prevent unbounded table growth:
  ```shell
  php artisan spectra:prune --hours=168
  ```

<a name="duplicate-requests-being-tracked"></a>
## Duplicate Requests Being Tracked

**Symptoms:** The same AI request appears multiple times in the dashboard.

Spectra tracks each request once. Every route into a provider (the HTTP client, the OpenAI SDK, the Guzzle stacks, and `withAITracking()`) uses the same middleware, and a request sent with `withAITracking()` is skipped by the global middleware. When two records have the same provider response id, Spectra updates the first one instead of adding a new one. This is also how polled async jobs (for example, video generation) stay one request. If you wrap a stream that Spectra already tracks in `Spectra::stream()`, it is recorded once.

**Causes:**

- **Retry middleware.** If your HTTP client retries failed requests, each attempt is tracked as a separate request. This is intentional: every attempt uses tokens and costs money.

- **No response id.** Records are only merged when the provider sends a response id. If you track the same call twice yourself (for example, with `Spectra::track()` and a custom Guzzle middleware), and the response has no id, you get two records.

**Solution:** Use only one tracking mechanism per request path.

<a name="costs-changed-after-upgrade"></a>
## Costs Look Higher or Lower After Upgrading

**Symptoms:** After upgrading to Spectra 2.0, new requests cost more (or less) than the same requests did before.

This is expected. Spectra 2.0 prices requests more accurately:

- Prompt tokens now include cache reads and writes for every provider (Anthropic used to report only the uncached part), and cache writes are billed at their own rate.
- Reasoning tokens are now billed for every provider (Gemini thinking tokens and xAI reasoning tokens used to be left out).
- The tier the provider reports wins over the tier the request asked for.
- A cost the provider reports (OpenRouter, xAI) is used as is.
- The model the provider actually served is priced first.

Older rows keep their old values. Nothing is rewritten. See the [Upgrade Guide](/upgrade#what-changes-in-the-numbers) for details. To fix daily stats that 1.x counted more than once for polled jobs, run `php artisan spectra:rebuild-stats` once.

<a name="exchange-rate-warning"></a>
## "No Exchange Rate" Warning in the Log

**Symptoms:** The log shows a warning like `Spectra: no exchange rate from EUR to USD`.

A pricing catalog uses a different currency from your reporting currency (the built-in Scaleway catalog is in EUR). Without a rate, Spectra counts those prices 1:1, so the costs are wrong. Add the rate to `config/spectra.php`:

```php
'costs' => [
    'currency' => 'USD',
    'exchange_rates' => [
        'EUR' => 1.17, // 1 EUR is worth 1.17 USD
    ],
],
```

The warning is logged once per process. See [Currency Conversion](/costs#currency-conversion).

<a name="budgets-after-upgrade"></a>
## Budgets or Per-User Usage Show Nothing for Old Requests

**Symptoms:** In an app with a morph map, budgets and per-user usage ignore requests recorded before the upgrade.

Spectra 2.0 stores the trackable type as the morph alias (`$model->getMorphClass()`). Spectra 1.x stored the class name. Update the old rows once, as shown in the [Upgrade Guide](/upgrade#trackables-use-morph-aliases).

<a name="budget-alert-once"></a>
## Budget Alert Fired Only Once

**Symptoms:** A `BudgetThresholdReached` event fired once, and not again on later requests.

This is expected. Warning, critical, and exceeded alerts fire once per budget, limit, and period. See [Alerts Fire Once per Period](/budgets#alerts-fire-once).
