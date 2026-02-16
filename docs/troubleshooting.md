# Troubleshooting

This page covers common issues you may encounter when using Spectra and how to resolve them.

## No Requests Are Being Tracked

**Symptoms:** The dashboard shows no data and the `spectra_requests` table is empty.

**Checklist:**

1. **Spectra is enabled** — Verify the master switch is on:
   ```bash
   SPECTRA_ENABLED=true
   ```

2. **Watcher is enabled** — Automatic tracking requires the watcher to be active:
   ```bash
   SPECTRA_WATCHER_ENABLED=true
   ```

3. **Provider is registered** — The request hostname must match a configured provider in `spectra.providers`. For example, requests to `api.openai.com` are recognized automatically, but requests to a custom or self-hosted endpoint require a [custom provider](/custom-providers) to be registered.

4. **Endpoint is trackable** — The request path must match a trackable endpoint defined in the provider's handlers. For example, OpenAI tracks `/v1/chat/completions`, `/v1/embeddings`, and `/v1/images/generations`, but does not track `/v1/models` or other non-AI endpoints.

5. **Migrations have run** — Ensure the `spectra_requests` table exists:
   ```shell
   php artisan migrate
   ```

6. **Using a supported HTTP client** — Spectra automatically tracks requests made via Laravel's `Http` facade (HttpWatcher), the `openai-php/laravel` SDK (OpenAiWatcher), and Guzzle with Spectra middleware (GuzzleMiddleware). Direct `curl` calls or other HTTP clients are not intercepted automatically — use the [Guzzle middleware](/usage#guzzle-middleware) or [manual tracking](/usage#manual-tracking) for those.

## Requests Tracked But Cost Is Zero

**Symptoms:** Requests appear in the dashboard but `total_cost_in_cents` shows `0.000000`.

**Checklist:**

1. **Pricing class exists** — The pricing catalog must contain entries for the models you are using. Pricing is defined through `ProviderPricing` classes registered in `config/spectra.php` under `costs.pricing`. See [Pricing](/pricing) for details.

2. **Model name matches** — The internal name in the pricing class must match exactly what the provider returns in the response. Check the model name stored in your request records against the pricing class definitions.

3. **Pricing tier exists** — If you are using a non-standard tier (such as `batch` or `flex`), a pricing tier entry must exist for that tier in the provider's pricing class.

4. **Pricing unit matches metrics** — The `pricingUnit` on the model definition must correspond to the metrics available in the response. For example, a model with `pricingUnit('tokens')` requires non-null `prompt_tokens` and `completion_tokens` values.

5. **Cost tracking is enabled** — Verify in the configuration:
   ```php
   'costs' => [
       'enabled' => true,
   ],
   ```

## Dashboard Shows Empty Data

**Symptoms:** The dashboard loads but displays no requests or statistics.

**Checklist:**

1. **Dashboard is enabled** — Verify the dashboard is not disabled:
   ```bash
   SPECTRA_DASHBOARD_ENABLED=true
   ```

2. **Request persistence is on** — If `store_requests` is `false`, requests are tracked in memory but not persisted to the database:
   ```bash
   SPECTRA_STORE_REQUESTS=true
   ```

3. **Database connection is correct** — If using a separate database, ensure the dashboard queries the same connection configured in `storage.connection`.

4. **Authorization gate passes** — The `viewSpectra` gate must return `true` for your user. In local development this is automatic. In production, ensure the gate is defined:
   ```php
   Gate::define('viewSpectra', function ($user) {
       return $user->isAdmin();
   });
   ```

5. **Middleware is correct** — If you added `auth` to dashboard middleware, make sure you are logged in when accessing the dashboard.

## Queue Persistence Not Working

**Symptoms:** Requests are tracked but never appear in the database when using queue mode.

**Checklist:**

1. **Queue worker is running** — Ensure a worker is processing the queue:
   ```shell
   php artisan queue:work
   ```

2. **Queue configuration is correct** — Verify the connection and queue name match your worker:
   ```php
   'queue' => [
       'enabled' => true,
       'connection' => null, // or your queue connection
       'queue' => null,      // or your queue name
   ],
   ```

3. **Check failed jobs** — The `PersistSpectraRequestJob` may be failing silently:
   ```shell
   php artisan queue:failed
   ```

4. **Queue takes priority over after-response** — These modes are mutually exclusive. When `queue.enabled` is `true`, the `after_response` setting is ignored.

## After-Response Mode Not Working

**Symptoms:** `after_response` is enabled but requests appear to persist synchronously.

This is expected behavior in two scenarios:

- **Non-HTTP context** — After-response only works during web requests. Console commands, queue jobs, and scheduled tasks always persist synchronously because there is no HTTP response lifecycle to defer to.
- **Queue is enabled** — When `queue.enabled` is `true`, it takes priority and `after_response` is ignored.

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

4. **Hard limit is enabled** — With `hard_limit = false`, requests are allowed to proceed and only events are fired. Set `hard_limit = true` on the budget to block requests when exceeded.

## OpenTelemetry Export Not Appearing

**Symptoms:** OTEL is enabled but no traces appear in your observability backend.

**Checklist:**

1. **OTEL is enabled** in the configuration:
   ```bash
   SPECTRA_OTEL_ENABLED=true
   ```

2. **Endpoint is reachable** — Test connectivity to your OTLP endpoint:
   ```shell
   curl -v http://localhost:4318/v1/traces
   ```

3. **Authentication headers are configured** — Most cloud backends require authentication. Verify your headers in the configuration.

4. **Export timing** — In after-response mode, traces export after the HTTP response. In console or queue contexts, they export synchronously. If traces appear from CLI commands but not web requests, verify that your server supports terminable middleware.

5. **Timeout is sufficient** — If the OTLP endpoint is remote or slow, increase the timeout:
   ```bash
   SPECTRA_OTEL_TIMEOUT=30
   ```

## Stats Look Wrong After Data Changes

**Symptoms:** Dashboard charts or cost totals don't match the raw request data.

**Solution:** Rebuild the daily statistics aggregation from the raw request records:

```shell
php artisan spectra:rebuild-stats

# Or rebuild a specific date range
php artisan spectra:rebuild-stats --from=2026-01-01 --to=2026-01-31
```

## Media Files Not Being Saved

**Symptoms:** Image or video generation requests are tracked but no media files are downloaded to disk.

**Checklist:**

1. **Media persistence is enabled**:
   ```bash
   SPECTRA_MEDIA_ENABLED=true
   ```

2. **Disk is writable** — Ensure the configured filesystem disk and path exist and are writable by your application.

3. **API key is available** — Some providers require an API key to download media files. Ensure keys are configured in `spectra.api_keys`.

4. **URLs haven't expired** — Provider media URLs have limited lifetimes. If persistence is delayed (for example, via a queue with a long delay), the URLs may expire before download completes.

## Macro Not Available

**Symptoms:** `Http::withAITracking()` or `Http::withoutAITracking()` throws a "Method not found" error.

**Checklist:**

1. **Service provider is registered** — Spectra's service provider should be auto-discovered. If not, register it manually:
   ```php
   // config/app.php
   'providers' => [
       Spectra\SpectraServiceProvider::class,
   ],
   ```

2. **Spectra is enabled** — Macros are registered when Spectra boots:
   ```bash
   SPECTRA_ENABLED=true
   ```

3. **Check for conflicts** — Another package may have registered a macro with the same name. Laravel macros are global and the last registration wins.

## High Memory Usage

**Symptoms:** Application memory spikes when making many AI requests in a single process.

**Solutions:**

- **Enable queue persistence** to move database writes to background workers:
  ```bash
  SPECTRA_QUEUE_ENABLED=true
  ```

- **Disable payload storage** if you don't need request and response bodies:
  ```bash
  SPECTRA_STORE_REQUESTS=false
  SPECTRA_STORE_RESPONSES=false
  ```

- **Schedule pruning** to prevent unbounded table growth:
  ```shell
  php artisan spectra:prune --hours=168
  ```

## Duplicate Requests Being Tracked

**Symptoms:** The same AI request appears multiple times in the dashboard.

**Causes:**

- **Multiple watchers matching** — If both HttpWatcher and GuzzleWatcher intercept the same request, it may be tracked twice. The `response_id` unique constraint prevents true duplicates, but different watchers may generate different response identifiers.

- **Retry middleware** — If your HTTP client retries failed requests, each attempt is tracked as a separate request. This is intentional, as each retry attempt consumes tokens and incurs costs.

**Solution:** Use only one tracking mechanism per request path. If you use the OpenAI SDK with GuzzleWatcher, consider disabling HttpWatcher for those hosts, or rely exclusively on one watcher type.
