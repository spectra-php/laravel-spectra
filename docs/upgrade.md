# Upgrade Guide

<a name="trace-columns"></a>
## Trace Columns (2.x)

A 2.x release adds the `started_at` and `origin` columns to `spectra_requests`, for [traces](/usage#traces). Run the installer to publish and run the migration:

```shell
php artisan spectra:install
```

Nothing breaks if you upgrade first: until the migration runs, Spectra saves calls without the two columns and logs a warning. See [The Trace Columns Migration](/migrations#trace-columns-migration).

The same release adds the `spectra_spans` table and a `span_id` column for [spans](/usage#spans). `spectra:install` publishes both migrations. Until the spans migration runs, calls are recorded as before and spans are skipped. See [The Spans Migration](/migrations#spans-migration).

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

## Other Changes

- Saving a tracked request never throws into your app any more. Failures go to your exception handler.
- Provider error responses (4xx and 5xx) are stored with the provider's error message in the new `error_message` column.
- Budget warning, critical, and exceeded alerts fire once per budget, limit, and period (day, week, month). The "already sent" mark is kept in your cache store, so it holds across PHP-FPM requests and queue workers. See [Budgets](/budgets#alerts-fire-once).
- With `store_prompts` / `store_request_data` turned off, the raw request body is no longer saved (1.x still saved it).
- `capture_ip`, `capture_user_agent`, and `capture_request_id` are now saved in the new columns. Turn them off in `config/spectra.php` if you don't want them stored. See [Tracking Context](/configuration#tracking-context).
