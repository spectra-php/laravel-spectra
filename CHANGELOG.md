# Changelog

All notable changes to `laravel-spectra` will be documented in this file.

## Unreleased

#### Fixed

- Apps that opt into immutable dates with `Date::use(CarbonImmutable::class)` crashed on every dashboard request: the package type-hinted `Illuminate\Support\Carbon` but fed it values from `now()` and model datetime casts, which are then `Carbon\CarbonImmutable`. Date parameters, properties and return types now accept any `Carbon\CarbonInterface`. This also fixes OpenTelemetry span export, where the `instanceof` check silently turned every timestamp into `0`

## v2.2.0 - 2026-10-21

#### Added

- `Spectra::withTags($tags, $callback)` tags only the requests sent while the callback runs, including the requests Spectra tracks by itself. Calls nest, and the outer tags come back when a call ends, even if it throws. One request or job can now tag each of its AI calls on its own

#### Fixed

- `SpectraFake::startRequest()` ignored global tags, and a request's own metadata replaced the global metadata instead of being merged with it

## v2.1.0 - 2026-10-20

#### Added

- OpenRouter embeddings (`/api/v1/embeddings`) and reranking (`/api/v1/rerank`) are now tracked. Previously these requests matched no handler and were recorded without tokens or cost. Embeddings record input tokens; reranking records search units and, when reported, tokens. Both take their cost from OpenRouter's reported `usage.cost`, including the upstream cost for BYOK, like chat requests

## v2.0.1 - 2026-10-19

#### Fixed

- Migrations failed with "relation already exists" when Spectra used a dedicated `spectra.storage.connection`: the app's `migrate:fresh` only drops tables on the default connection, so the Spectra tables survived while the migrations showed as pending. Both migrations now skip tables and columns that already exist

## v2.0.0 - 2026-10-15

Spectra 2.0 fixes how requests are tracked and priced, updates every pricing
catalog, and ships a redesigned dashboard. See [UPGRADE.md](UPGRADE.md).

#### Tracking fixes

- Streaming through Laravel's HTTP client no longer breaks the app: 1.x read the whole stream before the app could, which left the app with an empty body and stored a zero-token row. Streams are now tracked automatically as the app reads them (HTTP client, Prism, Laravel AI SDK, openai-php, Guzzle stacks), including SSE, newline-delimited JSON and streamed JSON arrays, streams the app stops reading early, and error events sent inside a stream
- Anthropic prompt caching was billed far too low: cache reads were subtracted from `input_tokens` (which already excludes them) and cache writes were never billed. Prompt tokens now include cache reads and writes, and 5-minute and 1-hour writes bill at their own rates
- Gemini thinking tokens and tool-use prompt tokens, and xAI reasoning tokens, are now billed
- The pricing tier the provider reports (batch, flex, priority/fast, ultrafast) now wins over the request's own tier, so downgraded or project-default tiers are priced right
- A finished Replicate prediction threw an exception into the app's own `Http::post()` call (ISO timestamps were subtracted as numbers)
- Polled async jobs (video, background responses, predictions, queued jobs) were counted once per poll in daily stats and budgets; repeat writes now add only what changed
- OpenAI Responses latency was replaced by whole-second provider timestamps
- Trackables were stored with the class name but looked up by morph alias, so budgets and per-user usage found nothing in apps with a morph map
- An explicit trackable was replaced by the signed-in user
- Global tags, trackable, pricing tier and metadata leaked between queued jobs and Octane requests (`Spectra` is now a scoped service)
- Saving a tracked request could throw into the app (e.g. a duplicate daily-stat row under load); failures are now reported instead
- With prompt storage turned off, the raw request body was still saved
- Budget alerts fired on every PHP-FPM request after a threshold, but never again in long-running workers; they now fire once per period, using the cache
- Multipart requests (audio uploads) lost their model when the file came first, which priced them at $0
- Provider error responses are stored with the provider's message; connection failures are recorded and still thrown

#### Pricing

- New pricing dimensions: cache writes, long-context prices (`aboveInputTokens`), prices that change on a date (`since`), audio and image token rates, `fast` and `ultrafast` tiers, `megapixel`, `page` and `request` units, model aliases, retired models, per-catalog currency with exchange rates, provider-reported costs, and regional surcharges
- All 12 catalogs updated to current models and prices (October 2026), including Claude 5.x, GPT-6 / GPT-5.6, Gemini 3.8 / 4, Grok 4.5–4.7, and many corrections; retired models are kept for history and marked
- Model ids resolve through aliases, dated snapshots and routing suffixes, and the model the provider served is priced first

#### Requirements

- Laravel 12 or 13 (Laravel 11 support is dropped; it no longer receives security fixes). PHP 8.2–8.5. The test suite runs on Pest 3, 4 or 5.

#### Added

- One trace per request: all AI calls of the same HTTP request, queued job or Artisan command share a trace id, queued jobs dispatched during a request keep it, and an incoming W3C `traceparent` header is joined (its span becomes the OpenTelemetry parent). New `Spectra::currentTraceId()` and `Spectra::newTrace()`, and `tracking.trace_per_request` / `tracking.use_traceparent` config
- New columns: `cached_tokens`, `cache_write_tokens`, `page_count`, `currency`, `error_type`, `error_message`, `parent_span_id`, `conversation_id`, `conversation_turn`, `request_source`, `laravel_request_id`, `ip_address`, `user_agent` (and matching daily-stat totals)
- `spectra.costs.exchange_rates` config
- `spectra:prune --stats-days`; prune now deletes stored media and works in chunks
- OpenTelemetry spans follow the current GenAI semantic conventions (cache read/write, reasoning, stream, conversation and error attributes)
- Workbench demo data (`vendor/bin/testbench workbench:build`) for working on the dashboard

#### Dashboard

- Redesigned UI: crisper neutral palette, refined typography with tabular numbers, a cleaner header and navigation, sparklines, breakdown legends with amounts and shares, a colour-blind-safe chart palette that follows the light/dark theme, and a new request detail page with the input (uncached, cache read, cache write) and output (answer, reasoning) token breakdown, error details and request origin, and a Trace card that shows every AI call of the same request as a timeline with its total cost, tokens and time

## v1.3.0 - 2026-07-04

### 1.3.0 - 2026-07-04

#### Added

- Cohere embed and rerank handlers (`/v1` + `/v2` endpoints, billed via `meta.billed_units`) — embed/rerank requests were previously tracked with zero metrics and $0 cost
- Groq Whisper transcription handler (per-minute billing from the `verbose_json` duration)
- Mistral Voxtral audio handlers: transcription (per-minute via `usage.prompt_audio_seconds`) and TTS (per-character, with base64 `audio_data` media persistence)
- `ElevenLabsPricing` catalog (per-character rates from the published API pricing) — ElevenLabs TTS previously recorded $0; Ollama's deliberate $0 is now documented in the pricing docs
- `SearchMetrics` and a `searchCount` on the request context, so per-search models bill the actual number of billed search units (and never bill failed requests)
- Post-persist budget reporting: `BudgetExceeded` (with `wasBlocked = false`) now fires when the request that actually crosses a cost limit is recorded — pre-flight middleware can only see spend from previous requests (documented in the budgets guide)
- `spectra.storage.store_request_data` config key to control storage of raw API request parameters independently of `store_prompts` (defaults to following `store_prompts`)
- `PricingTier::Hd` for DALL·E 3 HD and Sora Pro — explicit `'hd'` tier requests previously fell back to standard pricing silently
- `total_reasoning_tokens` in `SpectraDailyStat::getAggregatedStats()`
- fal.ai and Scaleway provider logos in the dashboard
- Provider tracking tests for OpenRouter, Ollama, and Scaleway; endpoint tests for the media, config, and detail controllers; `EnforceBudgetLimit` middleware tests; characterization tests locking the dashboard endpoint numbers

#### Fixed

- OpenRouter image-generation responses were processed by the chat handler (wrong registration order), losing image counts, media persistence, and per-image cost
- Dashboard cost and latency aggregates were inconsistent: nine queries (overview stats, model-type breakdowns, top models, and the trackable stats/usage/latency endpoints) included failed requests in cost and latency sums while the rest excluded them — cost and latency now aggregate over successful (2xx) requests everywhere, while request counts still include failures
- `Spectra::costs()->monthly()` crashed on SQLite (`EXTRACT()` is not supported) — replaced with a driver-aware period expression covering SQLite, MySQL, and PostgreSQL
- Fractional-cent costs were truncated to integers in `UsageQueryBuilder::stats()`, `totalCostCents()`, and the `HasAiUsageStats` trait (`UsageStats::$totalCostInCents` is now a float)
- 3xx responses were counted in `request_count` but excluded from both `successful_count` and `failed_count`, breaking `success + failed = total`; anything outside 2xx now counts as failed
- Costs-by-user grouped by `trackable_id` alone, merging different polymorphic types that share an id; it now groups by type + id and resolves a `user_name` for the dashboard
- A stream that failed before its first chunk leaked its pending Guzzle context to the next stream, which inherited the wrong provider/model; empty streams no longer persist all-zero request rows
- Budget sums could exceed a limit through float noise alone — sums are rounded to storage precision before the conservative ceil
- Video downloads always sent `Content-Type: video/mp4`; the real stored extension (webm/mov/avi) is now used for the mime type and filename
- `spectra:rebuild-stats` silently did nothing when `--from` was after `--to` (now errors), and gained the missing `#[AsCommand]` attribute
- A typo'd `SPECTRA_DASHBOARD_LAYOUT` silently disabled stat filtering; unknown layouts now log a warning
- OpenAI Responses API image handler checked `prompt_tokens_details` before `input_tokens_details` for cached tokens
- Missing `dist/` assets now log an actionable warning instead of rendering a blank, unstyled dashboard; removed favicon references to files that don't ship

#### Changed

- Removed all 21 `src/Queries/` classes — dashboard aggregates now compose an internal `Support\Query\RequestAggregateQuery` fluent builder that owns the shared scaffolding (date ranges, success filtering, layout/trackable scoping, aggregate select bundles); the cost select alias is standardized to `cost`
- `SpectraRequest` now hides the raw `request`/`response` payloads from serialization by default — the requests list endpoint ships lightweight rows, and the detail endpoint re-exposes them explicitly
- The requests-list search matches model names and exact ids (request id, response id, trace id) instead of running unindexable `LIKE` scans over the full response payload
- Streaming detection is shared via `Support\Tracking\StreamDetector` instead of duplicated across the Guzzle middleware and the Http macro
- `ModelDefinition::pricingUnit()` accepts a `PricingUnit` enum or string and validates it
- `HasAiBudget::getBudgetStatus()` defaults to the configured budget provider/model instead of a hardcoded `gpt-4`
- Migrations are published via `publishesMigrations()`; `per_page` is clamped to 100; `composer analyse` sets a 1G memory limit
- Documentation drift fixed: pricing map (fal.ai, Scaleway, ElevenLabs), budget defaults, new storage key, and budget pre-flight semantics

## 1.3.0 - 2026-07-04

### Added

- Cohere embed and rerank handlers (`/v1` + `/v2` endpoints, billed via `meta.billed_units`) — embed/rerank requests were previously tracked with zero metrics and $0 cost
- Groq Whisper transcription handler (per-minute billing from the `verbose_json` duration)
- Mistral Voxtral audio handlers: transcription (per-minute via `usage.prompt_audio_seconds`) and TTS (per-character, with base64 `audio_data` media persistence)
- `ElevenLabsPricing` catalog (per-character rates from the published API pricing) — ElevenLabs TTS previously recorded $0; Ollama's deliberate $0 is now documented in the pricing docs
- `SearchMetrics` and a `searchCount` on the request context, so per-search models bill the actual number of billed search units (and never bill failed requests)
- Post-persist budget reporting: `BudgetExceeded` (with `wasBlocked = false`) now fires when the request that actually crosses a cost limit is recorded — pre-flight middleware can only see spend from previous requests (documented in the budgets guide)
- `spectra.storage.store_request_data` config key to control storage of raw API request parameters independently of `store_prompts` (defaults to following `store_prompts`)
- `PricingTier::Hd` for DALL·E 3 HD and Sora Pro — explicit `'hd'` tier requests previously fell back to standard pricing silently
- `total_reasoning_tokens` in `SpectraDailyStat::getAggregatedStats()`
- fal.ai and Scaleway provider logos in the dashboard
- Provider tracking tests for OpenRouter, Ollama, and Scaleway; endpoint tests for the media, config, and detail controllers; `EnforceBudgetLimit` middleware tests; characterization tests locking the dashboard endpoint numbers

### Fixed

- OpenRouter image-generation responses were processed by the chat handler (wrong registration order), losing image counts, media persistence, and per-image cost
- Dashboard cost and latency aggregates were inconsistent: nine queries (overview stats, model-type breakdowns, top models, and the trackable stats/usage/latency endpoints) included failed requests in cost and latency sums while the rest excluded them — cost and latency now aggregate over successful (2xx) requests everywhere, while request counts still include failures
- `Spectra::costs()->monthly()` crashed on SQLite (`EXTRACT()` is not supported) — replaced with a driver-aware period expression covering SQLite, MySQL, and PostgreSQL
- Fractional-cent costs were truncated to integers in `UsageQueryBuilder::stats()`, `totalCostCents()`, and the `HasAiUsageStats` trait (`UsageStats::$totalCostInCents` is now a float)
- 3xx responses were counted in `request_count` but excluded from both `successful_count` and `failed_count`, breaking `success + failed = total`; anything outside 2xx now counts as failed
- Costs-by-user grouped by `trackable_id` alone, merging different polymorphic types that share an id; it now groups by type + id and resolves a `user_name` for the dashboard
- A stream that failed before its first chunk leaked its pending Guzzle context to the next stream, which inherited the wrong provider/model; empty streams no longer persist all-zero request rows
- Budget sums could exceed a limit through float noise alone — sums are rounded to storage precision before the conservative ceil
- Video downloads always sent `Content-Type: video/mp4`; the real stored extension (webm/mov/avi) is now used for the mime type and filename
- `spectra:rebuild-stats` silently did nothing when `--from` was after `--to` (now errors), and gained the missing `#[AsCommand]` attribute
- A typo'd `SPECTRA_DASHBOARD_LAYOUT` silently disabled stat filtering; unknown layouts now log a warning
- OpenAI Responses API image handler checked `prompt_tokens_details` before `input_tokens_details` for cached tokens
- Missing `dist/` assets now log an actionable warning instead of rendering a blank, unstyled dashboard; removed favicon references to files that don't ship

### Changed

- Removed all 21 `src/Queries/` classes — dashboard aggregates now compose an internal `Support\Query\RequestAggregateQuery` fluent builder that owns the shared scaffolding (date ranges, success filtering, layout/trackable scoping, aggregate select bundles); the cost select alias is standardized to `cost`
- `SpectraRequest` now hides the raw `request`/`response` payloads from serialization by default — the requests list endpoint ships lightweight rows, and the detail endpoint re-exposes them explicitly
- The requests-list search matches model names and exact ids (request id, response id, trace id) instead of running unindexable `LIKE` scans over the full response payload
- Streaming detection is shared via `Support\Tracking\StreamDetector` instead of duplicated across the Guzzle middleware and the Http macro
- `ModelDefinition::pricingUnit()` accepts a `PricingUnit` enum or string and validates it
- `HasAiBudget::getBudgetStatus()` defaults to the configured budget provider/model instead of a hardcoded `gpt-4`
- Migrations are published via `publishesMigrations()`; `per_page` is clamped to 100; `composer analyse` sets a 1G memory limit
- Documentation drift fixed: pricing map (fal.ai, Scaleway, ElevenLabs), budget defaults, new storage key, and budget pre-flight semantics

## 1.2.0 - 2026-06-28

### Added

- `Spectra::forUser()` now accepts an integer user ID and loads the configured auth user model (`config('auth.providers.users.model')`) automatically, in addition to a `Model` instance
- Date-suffix tolerant pricing lookups — an undated catalog entry (e.g. `claude-opus-4-8`) now matches the dated snapshot id the API returns (e.g. `claude-opus-4-8-20260528`), and OpenAI-style `gpt-4o-2024-08-06` resolves to `gpt-4o`
- `search` pricing unit and `CostCalculator::calculateBySearches()` for per-search billed models (e.g. Cohere Rerank)
- New built-in models: Anthropic (Opus 4.8/4.7, Sonnet 4.6, Fable 5, Mythos 5), OpenAI (GPT-5.5, GPT-5.5 Pro, GPT-5.4 / mini / nano / pro), Google (Gemini 3.5 Flash, 3.1 Pro, 3.1 Flash-Lite, Embedding 2, 3.1 Flash TTS/Image, Veo 3.1 preview), xAI (Grok 4.3, Grok 4.20 variants, Grok Build 0.1), Mistral (Devstral 2, Mixtral 8x7B/8x22B, NeMo, Moderation, Voxtral TTS/Transcribe)
- Facade `@method` annotations for the `SpectraFake` testing helpers (`assertTracked`, `assertRequestCount`, …)
- `PricingCatalogInvariantsTest` and per-unit cost tests guarding against models that silently bill 0
- `declare(strict_types=1)` across the codebase, enforced via a `declare_strict_types` Pint rule

### Fixed

- Cohere `rerank-v3.5` (and any `search`-unit model) silently billed $0 because the pricing unit was unsupported and fell through to the token path
- Cost was calculated twice — `RequestContext` produced a token-only cost that `RequestPersister` silently overwrote. Both now share a single `RequestCostCalculator`, so the in-memory cost is unit-aware and matches the persisted value
- Corrected stale Mistral prices (Medium 3.5, Small 4, and `mistral-embed` which was priced 10× too low)
- `PruneCommand::handle()` now returns an exit code

### Changed

- Refreshed the built-in pricing catalog to current provider rates (Anthropic, OpenAI, Google, xAI, Mistral)
- Collapsed duplicated provider handler logic into a shared `OpenAiCompatibleChatHandler` base class and an `ExtractsModelField` trait
- `composer analyse` now analyses `src` only (matching CI) instead of erroring on test-only static-analysis false positives
- Removed the dead `Provider::isStreamingResponse()` method and the unused empty `resources/pricing` directory
- Documented a "Keeping Prices Current" workflow (per-provider source URLs + last-reviewed date) in the pricing docs

## v1.1.0 - 2026-06-05

### 1.1.0

#### Fixed

- Widen `trackable_id` from UUID to string on `spectra_requests` and `spectra_daily_stats` so trackable models can use UUIDs, ULIDs, or integer primary keys (a new follow-up migration handles the schema change)
- Remove `formatted_expires_at` from `SpectraRequest::$appends` and append it explicitly in `GetRequestDetails`, preventing `MissingAttributeException` on partial-select endpoints when the consuming app enables strict-mode models

## 1.1.0

### Fixed

- Widen `trackable_id` from UUID to string on `spectra_requests` and `spectra_daily_stats` so trackable models can use UUIDs, ULIDs, or integer primary keys (a new follow-up migration handles the schema change)
- Remove `formatted_expires_at` from `SpectraRequest::$appends` and append it explicitly in `GetRequestDetails`, preventing `MissingAttributeException` on partial-select endpoints when the consuming app enables strict-mode models

## 1.0.0

### Added

- Add support for [Scaleway](https://www.scaleway.com) provider (Generative APIs: chat, embeddings, and Whisper transcription)

## 1.0.0-beta.1 - 2026-06-03

### Added

- Add support for Laravel 13

## 0.2.0 - 2026-01-26

### Breaking Changes

- Extract `extractModelFromResponse()` from `Handler` contract into a new `ExtractsModelFromResponse` interface. Custom handlers that implement this method must now implement `ExtractsModelFromResponse` separately.

### Added

- Add support for [fal.ai](https://fal.ai) provider (image generation)
- Add `ExtractsModelFromResponse` contract for handlers that extract model names from response payloads

### Changed

- Make GPT-5.2 the default model in `budget` instead of GPT-4
- Update `custom-providers.md` documentation

## 0.1.0 - 2026-01-16

- Initial release
