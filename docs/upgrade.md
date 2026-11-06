# Upgrade Guide

<a name="from-3x-to-4"></a>
## From 3.x to 4.0

Spectra 4.0 adds [scores](/scores), [alerts](/alerts), [redaction](/configuration#redaction), payload sampling, [prompt versions](/prompts), [tool spans](/tools), error grouping, [review queues](/annotations), [evaluations](/evaluations) and a [Maintenance screen](/dashboard#maintenance). Most apps only need to run the installer once. Read the rest of this section if `spectra:install` runs in a deploy script, if others use your dashboard in production, if your code reads `RequestContext::$id`, or if you wrote a pricing class or bill with the scraped prices file.

The requirements are unchanged: PHP 8.2 or newer (8.3 for Laravel 13), and Laravel 12 or 13.

**Quick checklist:**

1. [Run the installer](#v4-migrations) for the seven new migrations, then `spectra:group-errors` (recommended).
2. If `spectra:install` runs in a deploy script, [run `migrate` after it](#v4-install-scripts).
3. If others use your dashboard in production, [define the new gates](#v4-gates).
4. If you kept `RequestContext::$id`, [it is the saved id now](#v4-request-context).
5. If you report on failed requests or read the daily stats, [recheck the numbers and rebuild the stats](#v4-failed-requests).
6. If you extended a pricing class, wrote one for a custom provider, or bill with the scraped prices file, [move the prices to a pricing file](#v4-pricing-file).

<a name="v4-migrations"></a>
### Run the Installer (Recommended)

4.0 adds [the scores migration](/migrations#scores-migration), [the prompt columns migration](/migrations#prompt-columns-migration), [the error fingerprint migration](/migrations#error-fingerprint-migration), [the annotation migration](/migrations#annotation-migration), [the evaluation migration](/migrations#evaluation-migration), [the budgetable id migration](/migrations#budgetable-id-migration) and [the trace id migration](/migrations#trace-id-migration). The installer publishes only the ones your app doesn't have yet, then asks if it should run them:

```shell
php artisan spectra:install
```

Nothing breaks if you upgrade first. Until they run, calls are recorded as before, the new columns are skipped, `Spectra::score()` returns `null`, no trace is queued for review, and Spectra logs one warning per process. The Prompts and Errors screens say which migration is missing, and so do the Traces and Conversations screens and the request page's trace card while the 3.0 trace columns are missing. If your app is on PostgreSQL, run the budgetable id and trace id migrations before you rely on [budgets](/budgets) for users with integer or ULID keys, or on `Spectra::withTraceId()` with ids that aren't UUIDs: without them, those budgets and calls can't be saved there.

Then group the failures saved before the error fingerprint column existed:

```shell
php artisan spectra:group-errors
```

A published `config/spectra.php` doesn't need to be published again. The new keys (`alerts`, `annotations`, `storage.redaction`, `storage.sampling`, `tracking.tool_spans`, `dashboard.maintenance`) default to 3.x behaviour, and their `SPECTRA_*` environment variables work under the published config. Publish it again only to set the new options in the file.

<a name="v4-install-scripts"></a>
### `spectra:install` in Scripts No Longer Migrates

Without a terminal (`--no-interaction`, a Composer script), the installer now publishes the new migrations and stops, so it can sit in your `post-update-cmd` scripts. Run `php artisan migrate` after it in your deploy script. See [`spectra:install`](/commands).

<a name="v4-gates"></a>
### Two New Dashboard Gates

The Maintenance screen can prune data and pause recording, so its actions need the `manageSpectra` gate. Like `viewSpectra`, it allows only the `local` environment unless you define it (see [Dashboard Authorization](/installation#dashboard-authorization)):

```php
Gate::define('manageSpectra', fn ($user) => $user->isAdmin());
```

Reviewing traces and adding dataset examples from the dashboard need `annotateSpectra`, which allows whoever `viewSpectra` allows unless you define it (see [Who Can Review](/annotations#who-can-review)). Set `dashboard.maintenance` to `false` to keep the maintenance actions on the command line.

<a name="v4-request-context"></a>
### `RequestContext::$id` Is the Saved Id

`RequestContext::$id` is now the UUID the call is saved under, so code inside `Spectra::track()` can [score the call](/scores) before it is saved. It used to be a separate ULID that was never stored; if you kept it, it won't match any row.

<a name="v4-failed-requests"></a>
### Failed Requests and the Daily Stats

A request is failed when its status code is outside 200–299 or missing, as before, and now also when an error was recorded on a 2xx answer: an async job the provider reports as `failed` inside an HTTP 200 (a Replicate prediction, an OpenAI background response, an OpenRouter video), or a stream that broke off before its end. Those calls keep their `200` status code and are stored with the provider's error; they used to count as successes everywhere. Now `SpectraRequest::failed()`, `isFailed()`, the `is_failed` attribute sent to integrations, the dashboard counts and error rates, the Errors screen, the alerts and `Spectra::usage()` all treat them as failed, and their cost and latency stay out of the totals.

The daily stats (`spectra_daily_stats`) follow the same convention as the live numbers now: `total_cost_in_cents`, `total_latency_ms` and the latency bounds cover successful requests only, and `avg_latency_ms` divides by the successful count. They used to add every request's cost and latency, failed ones included, so the stats over-reported spend and latency where calls failed. Rebuild them once after upgrading:

```shell
php artisan spectra:rebuild-stats
```

The stats are rebuilt from the request records that still exist, so if you pruned old requests, rebuild only the days you still have requests for, with `--from` (see [`spectra:rebuild-stats`](/commands#spectra-rebuild-stats)). Reports built on the old numbers change; recheck them after upgrading.

<a name="v4-pricing-file"></a>
### Prices Come from a File

The pricing catalog is no longer a set of PHP classes but a JSON file bundled with the package, `resources/pricing/catalog.json`, generated from the providers' pricing pages and reviewed before each release. The `Spectra\Pricing\*Pricing` classes, `ProviderPricing::define()` and `populate()`, the `costs.pricing` config map and the `costs.use_scraped_prices` and `costs.scraped_prices_path` keys are gone. See [Pricing](/pricing).

- If you only used the built-in prices, nothing changes. A published config that still lists `costs.pricing` keeps working; Spectra logs one warning per process until you remove the key.
- If you extended a pricing class, or wrote one for a custom provider, move its models to [your own pricing file](/pricing#your-own-prices) and set `SPECTRA_PRICING_PATH`. The file's fields are the builder's parameters in snake_case (`inputPrice` is `input_price`, `aliases()` is `aliases`, `retiredOn()` is `retired_on`), and `toolCallPricing()` becomes the provider's `tool_calls`. A model in your file replaces the bundled model with the same id, so list every tier row it needs.
- If you billed with the scraped prices file, set `SPECTRA_PRICING_PATH` to its path. Until you do, a published config with `use_scraped_prices` on still reads `scraped_prices_path`.
- [`spectra:pricing-scrape`](/commands#spectra-pricing-scrape) writes to `SPECTRA_PRICING_PATH` (default `resources/spectra/pricing.json`). There is no switch to bill with the file any more: setting the path is the switch.

The lookup's index is now kept in your cache store (`costs.cache`, on by default) and rebuilt when a pricing file changes. Set `SPECTRA_PRICING_CACHE=false` to parse the files once per process instead, as before. See [Caching](/pricing#caching).

<a name="v4-other-changes"></a>
### Other Changes

- `spectra:prune` also deletes scores and review queue items older than the cutoff.
- While recording is paused, scores aren't stored either.
- A queued job whose parent request or job made no AI call now also saves that parent's span, marked `spectra.inferred`, so a trace has no gaps (see [Root spans](/usage#spans)).
- A conversation turn's message and reply skip side calls, such as guardrails that ask for JSON and query rewriters.

<a name="from-2x-to-3"></a>
## From 2.x to 3.0

Spectra 3.0 adds traces, spans and conversations, fixes several billing and budget bugs, and closes a gap that let a client choose which provider its budget was checked against. Most apps only need to run the installer once. Read the rest of this page if you use budgets, if your published `config/spectra.php` removes built-in providers, if your code reads or sets `RequestContext` properties, or if you report on failed requests.

The requirements are unchanged: PHP 8.2 or newer (8.3 for Laravel 13), and Laravel 12 or 13.

**Quick checklist:**

1. [Run the installer](#trace-columns) for the two new migrations (recommended).
2. If you turned off a built-in provider or price by deleting it from your config, [set it to `false`](#v3-built-in-providers).
3. If you use the `spectra.budget` middleware without arguments and relied on the request's `provider` / `model` input, [pass them as arguments](#v3-budget-middleware).
4. If you listen for `BudgetExceeded`, [check `limitType`](#v3-budget-exceeded) before reading the amounts as cents.
5. If your code touches `RequestContext` lists, [compare with `[]`, not `null`](#v3-request-context).
6. If you report on failed requests or their cost, [recheck the numbers](#v3-failed-requests).

<a name="trace-columns"></a>
### Run the Installer (Recommended)

3.0 adds the `started_at` and `origin` columns to `spectra_requests` for [traces](/usage#traces), and the `spectra_spans` table and a `span_id` column for [spans](/usage#spans). Run the installer. It publishes only the migrations your app doesn't have yet, then asks if it should run them:

```shell
php artisan spectra:install
```

Nothing breaks if you upgrade first. Until the migrations run, calls are recorded as before, the new columns and spans are skipped, and Spectra logs one warning per process. See [The Trace Columns Migration](/migrations#trace-columns-migration) and [The Spans Migration](/migrations#spans-migration).

A published `config/spectra.php` doesn't need to be published again. The new `tracking.capture_origin` and `tracking.root_spans` keys default to `true`.

<a name="v3-built-in-providers"></a>
### Built-in Providers Stay On Under a Published Config

Built-in providers and pricing classes now stay active under your published config, so an older config no longer misses providers and prices added later. If you turned one off by deleting its entry, it is back on. Set it to `false` instead:

```php
// config/spectra.php
'providers' => [
    'replicate' => false,
],

'costs' => [
    'pricing' => [
        'replicate' => false,
    ],
],
```

<a name="v3-budget-middleware"></a>
### The Budget Middleware Takes Its Provider and Model from the Route

The `spectra.budget` middleware no longer reads `provider` or `model` from the request input, because a client could name an allowed model and get past `allowed_providers` / `allowed_models`. It uses the route arguments, then `budget.default_provider` and `budget.default_model` from your config:

```php
Route::middleware(['auth', 'spectra.budget:openai,gpt-4o'])
    ->post('/chat', ChatController::class);
```

If a route relied on the request input, name the provider and model in its middleware, or set the defaults in `config/spectra.php`. See [Budgets](/budgets).

<a name="v3-budget-exceeded"></a>
### `BudgetExceeded` Fires for Every Hard Block

`BudgetExceeded` (`wasBlocked: true`) used to fire only when a cost limit blocked a request. It now fires before every hard block, so `currentUsage` and `limit` are not always cents. Check `limitType` first:

| `limitType` | `currentUsage` and `limit` are |
| --- | --- |
| `daily`, `weekly`, `monthly`, `total` | cents |
| `daily_tokens`, `weekly_tokens`, `monthly_tokens`, `total_tokens` | tokens |
| `daily_requests`, `weekly_requests`, `monthly_requests` | requests |
| `provider`, `model` (not on the allowed list) | both `0` |

```php
use Illuminate\Support\Facades\Event;
use Spectra\Events\BudgetExceeded;

Event::listen(function (BudgetExceeded $event) {
    if (in_array($event->limitType, ['daily', 'weekly', 'monthly', 'total'], true)) {
        $dollars = $event->getOverageAmountInDollars();
    }
});
```

<a name="v3-request-context"></a>
### `RequestContext` Lists Are Arrays

These `RequestContext` properties are now `array` and start as `[]` instead of `null`: `messages`, `tools`, `requestParameters`, `requestData`, `toolCalls`, `toolCallCounts` and `mediaStoragePath`.

```php
// 2.x
if ($context->requestData === null) { /* ... */ }
$context->toolCallCounts = null;

// 3.0
if ($context->requestData === []) { /* ... */ }
$context->toolCallCounts = [];
```

Assigning `null` to one of them now throws a `TypeError`. Passing `null` for one to `new RequestContext([...])` still works and means empty, and empty lists are still stored as `NULL`.

<a name="v3-failed-requests"></a>
### Failed Requests and Their Cost

A failed request is now any status outside 200-299, or none, everywhere:

- `SpectraRequest::failed()` and `isFailed()` include 3xx responses, like the dashboard already did. The exported `is_failed` attribute does too.
- `UsageQueryBuilder` (`stats()`, `byProvider()`, `byModel()`, `dailyUsage()`, `totalCostCents()`, `averageLatency()`) and the `HasAiUsage` methods count failed requests in request totals, but leave them out of cost and latency. So `->failed()->totalCostCents()` is `0`.

Reports built on the old numbers change. Recheck them after upgrading.

<a name="v3-what-changes-in-the-numbers"></a>
### What Changes in the Numbers

Older rows keep their old values. Nothing is rewritten.

- **OpenAI 12-hour cache writes** (GPT-5.6 and later, Responses API) are billed at their own rate, 2.5x input. They were billed at the 30-minute rate (1.25x), so these costs go up.
- **Token totals** from `UsageQueryBuilder::byProvider()`, `byModel()` and `dailyUsage()`, and from the `HasAiUsage` methods, are the real sums. They were `0`.
- **Budget spend** for a recorded request is rounded up to whole cents like every other budget total.
- **A usage field of the wrong type** (an array, a word) counts as `0` tokens.

<a name="v3-other-changes"></a>
### Other Changes

- With `store_system_prompts` or `store_tools` turned off, system prompts and tool definitions are now removed from the stored request body too. Rows saved before 3.0 still hold them. Prune or clear them if that matters to you.
- `SpectraRequest::$trackable_id` is always a string, also on a row just created from an integer key.
- A whitespace-only API key in config counts as unset.
- Pricing: dated ids that only add a date to a model id (`claude-sonnet-4-20250514`) are no longer listed by `getAliases()`. They still resolve to the same price.
- The Firecrawl settings read `SPECTRA_FIRECRAWL_API_KEY`, `SPECTRA_FIRECRAWL_API_URL` and `SPECTRA_FIRECRAWL_TIMEOUT`. The unprefixed `FIRECRAWL_*` names still work.

<a name="from-1x-to-2"></a>
## From 1.x to 2.0

Spectra 2.0 fixes how requests are tracked and priced. Most apps only need to run the new migration. Read the rest of this page if you built a custom provider or a custom span builder, or if you have dashboards on top of Spectra's OpenTelemetry data.

**Quick checklist:**

1. Check the [requirements](#requirements).
2. [Run the new migration](#run-the-migration) (required).
3. [Rebuild the daily stats](#rebuild-daily-stats) (recommended).
4. If your app has a morph map, [update the trackable type](#trackables-use-morph-aliases) of old rows.
5. If you use a catalog in another currency (Scaleway is in EUR), [add an exchange rate](#pricing-catalogs).
6. If you use OpenTelemetry, update your dashboards for the [new attribute names](#opentelemetry).

<a name="requirements"></a>
## Requirements

Spectra 2.0 needs:

- PHP 8.2 or newer (Laravel 13 needs PHP 8.3 or newer)
- Laravel 12 or 13. Laravel 11 is no longer supported.

<a name="run-the-migration"></a>
## Run the New Migration (Required)

Spectra 2.0 adds columns for prompt-cache usage, page counts, error details, conversation ids, and request origin. Run the installer again. It publishes only the migrations your app doesn't have yet, then asks if it should run them:

```shell
php artisan spectra:install
```

Don't use `vendor:publish --tag=spectra-migrations` for the upgrade. Laravel gives published migrations a new date, so it would copy Spectra's older migrations again. See [The 2.0 Migration](/migrations#v2-migration) for details.

<a name="rebuild-daily-stats"></a>
## Rebuild the Daily Stats (Recommended)

Spectra 1.x counted repeated polls of async jobs (for example, video generation) more than once in the daily stats. Rebuild them once after upgrading:

```shell
php artisan spectra:rebuild-stats
```

The stats are rebuilt from the request records that still exist. If you pruned old requests, rebuild only the days you still have requests for, with `--from` (see [`spectra:rebuild-stats`](/commands#spectra-rebuild-stats)).

<a name="what-changes-in-the-numbers"></a>
## What Changes in the Numbers

Costs recorded from now on are more accurate. Older rows keep their old values. Nothing is rewritten.

- **Prompt tokens include cached tokens for every provider.** Anthropic used to report only the uncached part of the prompt. Now `prompt_tokens` is the full prompt (uncached + cache reads + cache writes), as it already was for OpenAI and Google. The breakdown is stored in the new `cached_tokens` and `cache_write_tokens` columns.
- **Completion tokens include reasoning tokens for every provider.** Gemini thinking tokens and xAI reasoning tokens used to be left out of the cost.
- **Cache writes are billed** at their own price (Anthropic 5-minute and 1-hour writes, and OpenAI GPT-5.6 and newer).
- **The pricing tier the provider reports wins.** If you ask for `priority` and the provider serves the request at `standard`, it is priced at `standard`. A tier you set with `Spectra::withPricingTier()` or the `pricing_tier` option still wins over everything.
- **A cost the provider reports is used as is** (OpenRouter `usage.cost`, xAI `cost_in_usd_ticks`).
- **The model the provider actually served is priced first**, then the model you asked for. This matters for aliases, routers, and retired models that redirect.

So new costs for the same requests can be higher or lower than before. See [Costs](/costs) for the full formula.

<a name="trackables-use-morph-aliases"></a>
## Trackables Use Morph Aliases

The trackable type is now stored as `$model->getMorphClass()` instead of the class name.

- Apps **without** a morph map: nothing changes.
- Apps **with** a morph map: budgets and per-user usage did not find 1.x rows, because 1.x stored the class name. Update the old rows once:

```php
use Illuminate\Database\Eloquent\Relations\Relation;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;

foreach (Relation::morphMap() as $alias => $class) {
    SpectraRequest::where('trackable_type', $class)->update(['trackable_type' => $alias]);
    SpectraDailyStat::where('trackable_type', $class)->update(['trackable_type' => $alias]);
}
```

An explicit trackable (`Spectra::forTrackable()`, `Spectra::forUser()`, or the `trackable_type` / `trackable_id` options) now always wins over the signed-in user.

<a name="streaming"></a>
## Streaming Is Tracked Automatically

Streamed responses are now recorded on their own, as your code reads the stream. This works for Laravel's HTTP client (and so Prism and Laravel AI), the OpenAI PHP client, and the tracked Guzzle stacks. See [Streaming](/usage#streaming).

- `Spectra::stream()` still works. You only need it for SDKs that don't use one of these clients.
- If you wrap a stream that Spectra already tracks, it is recorded once, not twice.
- `StreamingTracker::finish()` can be called more than once. Later calls return the first result.

You can remove `Spectra::stream()` from code that streams through the clients above, but you don't have to.

<a name="global-settings"></a>
## Global Settings Reset per Job and Request

`Spectra` is now bound as a *scoped* service. Global tags, trackable, pricing tier, trace id, and metadata (`Spectra::addGlobalTags()`, `forUser()`, ...) are reset between queued jobs and Octane requests. They no longer leak into the next job or request.

Set them where the work happens: in the job, or in middleware. If you set them once in a service provider and expect them in every queued job, move that code into the job.

<a name="http-client-middleware"></a>
## HTTP Client Tracking Moved from Events to Middleware

`HttpWatcher` now adds Spectra as global HTTP client middleware. It no longer listens to the `RequestSending` and `ResponseReceived` events, and it no longer reads a response body before your code does.

- `Http::withAITracking()` and `Http::withoutAITracking()` work as before. They no longer send the `X-Spectra-Manual-Tracking` header to the provider. If you set this header yourself, Spectra still honours it.
- If you called `HttpWatcher::handleRequestSending()` (or the other event handlers) directly, remove those calls.

<a name="custom-providers"></a>
## Custom Providers

If you wrote your own provider or handler, check these changes. See [Custom Providers](/custom-providers) for the details.

- `TokenMetrics` has new fields. `cacheCreationTokens` was renamed to **`cacheWriteTokens`**. `cacheWrite1hTokens`, `audioInputTokens`, `imageInputTokens`, `audioOutputTokens`, and `imageOutputTokens` were added.
- `promptTokens` must be the **full** prompt, and `completionTokens` the **full** output (see [What Changes in the Numbers](#what-changes-in-the-numbers)).
- `Metrics` has new fields: `document` (`DocumentMetrics`), `toolCalls` (billable server-tool usage), and `reportedCostInCents`. `ImageMetrics` has a new `megapixels` field.
- New optional contracts: `ExtractsCostMultiplier` (on the provider) and `HasProviderLatency` (on a handler, for async jobs). Spectra no longer works out the latency from `completed_at - created_at` in the response. Implement `HasProviderLatency` if you need it.
- Stream handlers may return the extra usage keys `cache_write_tokens`, `cache_write_1h_tokens`, `reported_cost_in_cents`, `tool_calls`, `pricing_tier`, and `cost_multiplier`.

```php
// 1.x
new TokenMetrics(promptTokens: 100, completionTokens: 50, cacheCreationTokens: 20);

// 2.0
new TokenMetrics(promptTokens: 120, completionTokens: 50, cacheWriteTokens: 20);
```

In 2.0, `promptTokens` also includes the 20 cache-write tokens.

<a name="pricing-catalogs"></a>
## Pricing Catalogs

If you extended a built-in pricing class or wrote your own, you can use these new options. See [Pricing](/pricing).

- New tier options: `aboveInputTokens` (long-context prices), `since` (prices that change on a date), `audioInputPrice`, `audioOutputPrice`, `imageInputPrice`, and `imageOutputPrice`.
- New model options: `->aliases(...)` and `->retiredOn('Y-m-d')`.
- New pricing units: `megapixel`, `page`, and `request`. New tiers: `fast` and `ultrafast`.
- `ProviderPricing::currency()` says which currency a catalog uses (default `USD`). The Scaleway catalog is in EUR. Add a rate under `spectra.costs.exchange_rates`, for example `'EUR' => 1.17` when you report in USD. Without a rate, EUR is counted 1:1 and Spectra logs a warning.
- Several built-in catalog ids were renamed to the provider's current ids. For example, dated Claude ids became undated, and Groq ids gained their `openai/` prefix. The old ids are kept as aliases, and the display names did not change, so existing dashboards are not affected.

<a name="opentelemetry"></a>
## OpenTelemetry Attribute Names

The default span builder follows the current [OpenTelemetry GenAI conventions](https://github.com/open-telemetry/semantic-conventions-genai).

| 1.x | 2.0 |
| --- | --- |
| span name `ai.{provider}.{model}` | `{operation} {model}`, e.g. `chat gpt-4o` |
| `gen_ai.system` | `gen_ai.provider.name` (`gcp.gemini` for Google, `x_ai` for xAI) |
| `gen_ai.operation.name` = endpoint path | `gen_ai.operation.name` = `chat`, `embeddings`, `generate_content`, ...; the path moved to `spectra.endpoint` |
| `gen_ai.usage.prompt_tokens` | `gen_ai.usage.input_tokens` |
| `gen_ai.usage.completion_tokens` | `gen_ai.usage.output_tokens` |
| `gen_ai.usage.total_tokens` | removed (input + output) |
| `gen_ai.usage.reasoning_tokens` | `gen_ai.usage.reasoning.output_tokens` |
| `gen_ai.response.finish_reason` | `gen_ai.response.finish_reasons` (array) |
| `spectra.is_streaming` | `gen_ai.request.stream` |
| None (new in 2.0) | `gen_ai.usage.cache_read.input_tokens`, `gen_ai.usage.cache_write.input_tokens`, `gen_ai.conversation.id`, `error.type`, `spectra.currency`, `spectra.model` |

If you rely on the old names, extend `DefaultSpanBuilder` and override `attributes()` or `spanName()`. See [Customizing Spans](/opentelemetry#customizing-spans).

<a name="prune"></a>
## `spectra:prune`

- Daily stats are no longer deleted together with requests. Pass `--stats-days=N` to prune them too.
- Media files stored for a pruned request are now deleted with it.
- The command deletes in chunks (`--chunk`, default 1000).

If you schedule `spectra:prune`, decide how long to keep daily stats and add `--stats-days`. See [`spectra:prune`](/commands#spectra-prune).

<a name="other-changes"></a>
<a name="one-trace-per-request"></a>
## One Trace per Request

In 1.x every AI call got its own trace id unless you called `Spectra::withTraceId()`. In 2.0 all AI calls of the same HTTP request, queued job, or Artisan command share one trace id. Queued jobs dispatched during a request keep that trace. When the incoming request has a W3C `traceparent` header, its trace id is used. See [Traces](/usage#traces).

The new migration adds a `parent_span_id` column for the `traceparent` span. To keep the 1.x behaviour, set `SPECTRA_TRACE_PER_REQUEST=false`.

A long-running Artisan command that handles many separate items puts all of their AI calls in one trace. Call `Spectra::newTrace()` for each item if you want them apart.

<a name="v2-other-changes"></a>
## Other Changes

- Saving a tracked request never throws into your app any more. Failures go to your exception handler.
- Provider error responses (4xx and 5xx) are stored with the provider's error message in the new `error_message` column.
- Budget warning, critical, and exceeded alerts fire once per budget, limit, and period (day, week, month). The "already sent" mark is kept in your cache store, so it holds across PHP-FPM requests and queue workers. See [Budgets](/budgets#alerts-fire-once).
- With `store_prompts` / `store_request_data` turned off, the raw request body is no longer saved (1.x still saved it).
- `capture_ip`, `capture_user_agent`, and `capture_request_id` are now saved in the new columns. Turn them off in `config/spectra.php` if you don't want them stored. See [Tracking Context](/configuration#tracking-context).
