# Upgrade Guide

## From 3.x to 4.0

Spectra 4.0 adds scores, alerts, redaction, payload sampling, prompt
versions, tool spans, error grouping, review queues, evaluations and a
maintenance screen. Most apps only need to run the installer once. Read the
rest of this section if `spectra:install` runs in a deploy script, if
others use your dashboard in production, or if your code reads
`RequestContext::$id`.

The full guide is at https://spectraphp.com/upgrade#from-3x-to-4.

### 0. Requirements

Unchanged: PHP 8.2 or newer (8.3 for Laravel 13), and Laravel 12 or 13.

### 1. Run the installer (recommended)

4.0 adds five migrations: `create_spectra_scores_table`,
`add_prompt_columns_to_spectra_requests_table`,
`add_error_fingerprint_to_spectra_requests_table`,
`create_spectra_annotation_items_table` and
`create_spectra_evaluation_tables`. The installer publishes only the ones
your app doesn't have yet and offers to run them:

```bash
php artisan spectra:install
```

Nothing breaks if you upgrade first: until they run, calls are recorded as
before, the new columns are skipped, `Spectra::score()` returns `null`, no
trace is queued for review, and Spectra logs one warning per process. The
dashboard's Prompts and Errors screens say which migration is missing.

Then group the failures saved before the error fingerprint column existed:

```bash
php artisan spectra:group-errors
```

A published config doesn't need to be published again; the new keys
(`alerts`, `annotations`, `storage.redaction`, `storage.sampling`,
`tracking.tool_spans`, `dashboard.maintenance`) have defaults that keep 3.x
behaviour.

### 2. `spectra:install` in scripts no longer migrates

Without a terminal (`--no-interaction`, a Composer script), the installer
now publishes the new migrations and stops. Run `php artisan migrate` after
it in your deploy script.

### 3. Two new dashboard gates

The Maintenance screen can prune data, pause recording and change prices,
so its actions need the `manageSpectra` gate. Like `viewSpectra`, it allows
only the `local` environment unless you define it:

```php
Gate::define('manageSpectra', fn ($user) => $user->isAdmin());
```

Reviewing traces and adding dataset examples from the dashboard need
`annotateSpectra`, which allows whoever `viewSpectra` allows unless you
define it. Set `dashboard.maintenance` to `false` to keep the maintenance
actions on the command line.

### 4. `RequestContext::$id` is the saved id

`RequestContext::$id` is now the UUID the call is saved under, so code inside
`Spectra::track()` can score the call before it is saved. It used to be a
separate ULID that was never stored; if you kept it, it won't match any row.

### 5. Other changes

- `spectra:prune` also deletes scores and review queue items older than the
  cutoff.
- While recording is paused, scores aren't stored either.
- A queued job whose parent request or job made no AI call now also saves
  that parent's span, marked `spectra.inferred`, so a trace has no gaps.
- A conversation turn's message and reply skip side calls such as
  guardrails that ask for JSON and query rewriters.

## From 2.x to 3.0

Spectra 3.0 adds traces, spans and conversations, fixes several billing and
budget bugs, and closes a gap that let a client choose which provider its
budget was checked against. Most apps only need to run the installer once.
Read the sections below if you use budgets, if your published
`config/spectra.php` removes built-in providers, if your code reads or sets
`RequestContext` properties, or if you report on failed requests.

The full guide is at https://spectraphp.com/upgrade#from-2x-to-3.

### 0. Requirements

Unchanged: PHP 8.2 or newer (8.3 for Laravel 13), and Laravel 12 or 13.

### 1. Run the installer (recommended)

3.0 adds the `started_at` and `origin` columns for traces, and the
`spectra_spans` table and a `span_id` column for spans. The installer
publishes only the migrations your app doesn't have yet and offers to run
them:

```bash
php artisan spectra:install
```

Nothing breaks if you upgrade first: until the migrations run, calls are
recorded as before, the new columns and spans are skipped, and Spectra logs
one warning per process. A published config doesn't need to be published
again; `tracking.capture_origin` and `tracking.root_spans` default to `true`.

### 2. Built-in providers stay on under a published config

Built-in providers and pricing classes now stay active under your published
config. If you turned one off by deleting its entry, it is back on. Set it to
`false` instead:

```php
'providers' => ['replicate' => false],
'costs' => ['pricing' => ['replicate' => false]],
```

### 3. The budget middleware takes its provider and model from the route

`spectra.budget` no longer reads `provider` or `model` from the request
input: a client could name an allowed model and get past
`allowed_providers` / `allowed_models`. It uses the route arguments, then
`budget.default_provider` and `budget.default_model`:

```php
Route::middleware(['auth', 'spectra.budget:openai,gpt-4o'])
    ->post('/chat', ChatController::class);
```

### 4. `BudgetExceeded` fires for every hard block

`BudgetExceeded` (`wasBlocked: true`) now fires before every hard block, not
only cost limits, so `currentUsage` and `limit` are not always cents. Check
`limitType` first:

| `limitType` | `currentUsage` and `limit` are |
| --- | --- |
| `daily`, `weekly`, `monthly`, `total` | cents |
| `daily_tokens`, `weekly_tokens`, `monthly_tokens`, `total_tokens` | tokens |
| `daily_requests`, `weekly_requests`, `monthly_requests` | requests |
| `provider`, `model` (not on the allowed list) | both `0` |

### 5. `RequestContext` lists are arrays

`messages`, `tools`, `requestParameters`, `requestData`, `toolCalls`,
`toolCallCounts` and `mediaStoragePath` are now `array` and start as `[]`
instead of `null`. Compare with `=== []` (or `blank()`), and assign `[]`
instead of `null`: assigning `null` throws a `TypeError`. Passing `null` for
one to `new RequestContext([...])` still means empty, and empty lists are
still stored as `NULL`.

### 6. Failed requests and their cost

A failed request is any status outside 200-299, or none, everywhere.
`SpectraRequest::failed()` / `isFailed()` and the exported `is_failed` now
include 3xx. `UsageQueryBuilder` and `HasAiUsageStats` count failed requests
in request totals but leave them out of cost and latency, so
`->failed()->totalCostCents()` is `0`. Recheck reports built on the old
numbers.

### 7. What changes in the numbers

Older rows keep their old values.

- OpenAI 12-hour cache writes (GPT-5.6 and later) are billed at 2.5x input
  instead of the 30-minute 1.25x, so those costs go up.
- Token totals from `UsageQueryBuilder::byProvider()`, `byModel()`,
  `dailyUsage()` and the `HasAiUsageStats` methods are the real sums. They
  were `0`.
- Budget spend for a recorded request is rounded up to whole cents like
  every other budget total.
- A usage field of the wrong type (an array, a word) counts as `0` tokens.

### 8. Other changes

- With `store_system_prompts` or `store_tools` off, system prompts and tool
  definitions are now removed from the stored request body too. Rows saved
  before 3.0 still hold them; prune or clear them if that matters to you.
- `SpectraRequest::$trackable_id` is always a string.
- A whitespace-only API key in config counts as unset.
- Dated ids that only add a date to a model id (`claude-sonnet-4-20250514`)
  are no longer listed by `getAliases()`. They still resolve to the same
  price.
- Firecrawl reads `SPECTRA_FIRECRAWL_API_KEY`, `SPECTRA_FIRECRAWL_API_URL`
  and `SPECTRA_FIRECRAWL_TIMEOUT`; the unprefixed names still work.

## From 1.x to 2.0

Spectra 2.0 fixes how requests are tracked and priced. Most apps only need
to publish and run the new migration. Read the sections below if you built a
custom provider, a custom span builder, or dashboards on top of Spectra's
OpenTelemetry data.

### 0. Requirements

Spectra 2.0 needs Laravel 12 or 13 (Laravel 11 is no longer supported) and
PHP 8.2 or newer.

### 1. Run the new migration (required)

2.0 adds columns for prompt-cache usage, page counts, error details,
conversation ids and request origin. Run the installer again: it publishes
only the migrations your app doesn't have yet and offers to run them.

```bash
php artisan spectra:install
```

Don't use `vendor:publish --tag=spectra-migrations` for this. Laravel gives
published migrations a new date, so it would copy Spectra's older migrations
again and `migrate` would fail.

### 2. Rebuild daily stats (recommended)

1.x counted repeated polls of async jobs (for example video generation) more
than once in the daily stats. Rebuild them once after upgrading:

```bash
php artisan spectra:rebuild-stats
```

### 3. What changes in the numbers

Costs recorded from now on are more accurate. Older rows keep their old
values; nothing is rewritten.

- **Prompt tokens include cached tokens for every provider.** Anthropic used
  to report only the uncached part of the prompt. `prompt_tokens` is now the
  full prompt (uncached + cache reads + cache writes), as it already was for
  OpenAI and Google. The breakdown is stored in the new `cached_tokens` and
  `cache_write_tokens` columns.
- **Completion tokens include reasoning tokens for every provider.** Gemini
  thinking tokens and xAI reasoning tokens used to be left out of the bill.
- **Cache writes are billed** at their own rate (Anthropic 5-minute and
  1-hour writes, OpenAI GPT-5.6+ writes).
- **The pricing tier the provider reports wins.** If you ask for `priority`
  and the provider serves the request at `standard`, it is priced at
  `standard`. A tier you set with `Spectra::withPricingTier()` or the
  `pricing_tier` option still wins over everything.
- **A cost the provider reports is used as is** (OpenRouter `usage.cost`,
  xAI `cost_in_usd_ticks`).
- **The model the provider actually served is priced first**, then the model
  you asked for. This matters for aliases, routers and retired models that
  redirect.

### 4. Trackables use morph aliases

The trackable type is now stored as `$model->getMorphClass()` instead of the
class name. Apps **without** a morph map see no change. Apps **with** a morph
map: budgets and per-user usage did not find 1.x rows (they were stored with
the class name), so update the old rows once:

```php
use Illuminate\Database\Eloquent\Relations\Relation;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;

foreach (Relation::morphMap() as $alias => $class) {
    SpectraRequest::where('trackable_type', $class)->update(['trackable_type' => $alias]);
    SpectraDailyStat::where('trackable_type', $class)->update(['trackable_type' => $alias]);
}
```

An explicit trackable (`Spectra::forTrackable()` or the `trackable_type` /
`trackable_id` options) now always wins over the signed-in user.

### 5. Streaming is tracked automatically

Streamed responses through Laravel's HTTP client (and anything built on it,
such as Prism and the Laravel AI SDK), the OpenAI PHP client and the tracked
Guzzle stacks are now recorded on their own, as your code reads the stream.
`Spectra::stream()` still works and is still needed for SDKs that don't go
through one of these. If you wrap a stream that Spectra already sees, it is
recorded once, not twice.

`StreamingTracker::finish()` can be called more than once; later calls return
the first result.

### 6. Global settings reset per job and request

`Spectra` is now bound as a *scoped* service. Global tags, trackable, pricing
tier, trace id and metadata (`Spectra::addGlobalTags()`, `forUser()`, ...)
are reset between queued jobs and Octane requests instead of leaking into the
next one. Set them where the work happens (in the job, or in middleware).

### 7. HTTP client tracking moved from events to middleware

`HttpWatcher` now registers Spectra as global HTTP client middleware instead
of listening to `RequestSending` / `ResponseReceived`. It no longer reads a
response body before your code does.

- `Http::withAITracking()` and `Http::withoutAITracking()` work as before,
  but no longer send the `X-Spectra-Manual-Tracking` header to the provider.
  The header is still honoured if you set it yourself.
- If you called `HttpWatcher::handleRequestSending()` (or the other event
  handlers) directly, remove those calls.

### 8. Custom providers

- `TokenMetrics` gained fields. `cacheCreationTokens` was renamed to
  **`cacheWriteTokens`**, and `cacheWrite1hTokens`, `audioInputTokens`,
  `imageInputTokens`, `audioOutputTokens` and `imageOutputTokens` were added.
  `promptTokens` must be the **full** prompt and `completionTokens` the **full**
  output (see "What changes in the numbers").
- `Metrics` gained `document` (`DocumentMetrics`), `toolCalls` (billable
  server-tool usage) and `reportedCostInCents`. `ImageMetrics` gained
  `megapixels`.
- New optional contracts: `ExtractsCostMultiplier` (on the provider) and
  `HasProviderLatency` (on a handler, for async jobs). The persister no longer
  derives latency from `completed_at - created_at` in the response.
- Stream handlers may return the extra usage keys `cache_write_tokens`,
  `cache_write_1h_tokens`, `reported_cost_in_cents`, `tool_calls`,
  `pricing_tier` and `cost_multiplier`.

### 9. Pricing catalogs

- New tier options: `aboveInputTokens` (long-context prices), `since` (prices
  that change on a date), `audioInputPrice`, `audioOutputPrice`,
  `imageInputPrice`, `imageOutputPrice`.
- New model options: `->aliases(...)` and `->retiredOn('Y-m-d')`.
- New pricing units: `megapixel`, `page`, `request`. New tiers: `fast`,
  `ultrafast`.
- `ProviderPricing::currency()` (default `USD`). Scaleway's catalog is in EUR.
  Add a rate under `spectra.costs.exchange_rates` (for example
  `'EUR' => 1.17` when you report in USD). Without one, EUR is counted 1:1
  and Spectra logs a warning.
- Several built-in catalog ids were renamed to the provider's current ids
  (for example dated Claude ids became undated, Groq ids gained their
  `openai/` prefix). The old ids are kept as aliases and the display names
  did not change, so existing dashboards are unaffected.

### 10. OpenTelemetry attribute names

The default span builder follows the current OpenTelemetry GenAI conventions.

| 1.x | 2.0 |
| --- | --- |
| span name `ai.{provider}.{model}` | `{operation} {model id}`, e.g. `chat gpt-4o` |
| `gen_ai.system` | `gen_ai.provider.name` (`gcp.gemini` for Google, `x_ai` for xAI) |
| `gen_ai.operation.name` = endpoint path | `gen_ai.operation.name` = `chat`, `embeddings`, `generate_content`, ...; the path moved to `spectra.endpoint` |
| `gen_ai.usage.prompt_tokens` | `gen_ai.usage.input_tokens` |
| `gen_ai.usage.completion_tokens` | `gen_ai.usage.output_tokens` |
| `gen_ai.usage.total_tokens` | removed (input + output) |
| `gen_ai.usage.reasoning_tokens` | `gen_ai.usage.reasoning.output_tokens` |
| `gen_ai.response.finish_reason` | `gen_ai.response.finish_reasons` (array) |
| `spectra.is_streaming` | `gen_ai.request.stream` |
| — | `gen_ai.usage.cache_read.input_tokens`, `gen_ai.usage.cache_write.input_tokens`, `gen_ai.conversation.id`, `error.type`, `spectra.currency`, `spectra.model` |

If you rely on the old names, extend `DefaultSpanBuilder` and override
`attributes()` or `spanName()`.

### 11. `spectra:prune`

Daily stats are no longer deleted together with requests. Pass
`--stats-days=N` to prune them too. Media files stored for a pruned request
are now deleted with it.

### 12. One trace per request

In 1.x every AI call got its own trace id unless you called
`Spectra::withTraceId()`. In 2.0 all AI calls of the same HTTP request,
queued job, or Artisan command share one trace id, and queued jobs dispatched
during a request keep that trace. When the incoming request has a W3C
`traceparent` header, its trace id is used.

The new migration adds a `parent_span_id` column for the `traceparent` span.
To keep the 1.x behaviour, set `SPECTRA_TRACE_PER_REQUEST=false`.

A long-running Artisan command that handles many separate items puts all of
their AI calls in one trace. Call `Spectra::newTrace()` for each item if you
want them apart.

### 13. Other behaviour changes

- Saving a tracked request never throws into your app any more. Failures are
  reported through your exception handler.
- Provider error responses (4xx/5xx) are stored with the provider's error
  message in the new `error_message` column.
- Budget warning and critical events, and `BudgetExceeded` for a recorded
  request (`wasBlocked = false`), fire once per budget, limit and period
  (day, week, month). The "already sent" mark is kept in your cache store,
  so it holds across PHP-FPM requests and queue workers. `BudgetExceeded`
  with `wasBlocked = true` still fires for every request the middleware
  blocks.
- With `store_prompts` / `store_request_data` turned off, the raw request body
  is no longer saved (1.x still saved it).
- `capture_ip`, `capture_user_agent` and `capture_request_id` are now saved in
  the new columns. Turn them off in `config/spectra.php` if you don't want
  them stored.
