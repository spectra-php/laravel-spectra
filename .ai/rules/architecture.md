---
paths:
  - 'src/**'
  - 'config/**'
  - 'database/**'
  - 'routes/**'
---

# Architecture

## Know where each piece lives
One root namespace, `Spectra\` → `src/`. Put new code next to its kind, never in a new top-level folder without a reason:

| Folder | Holds |
|---|---|
| `Watchers/` | Hooks into Laravel `Http`, Guzzle and the OpenAI SDK that start tracking. |
| `Support/Tracking/` | The tracking pipeline: `GuzzleMiddleware`, `RequestContext`, `ResponseProcessor`, streaming, `RequestPersister` / `RequestWriter`, trace context. |
| `Providers/{Provider}/` | One class per provider plus its `Handlers/`, `Streaming/` and `Concerns/`. `.ai/rules/providers.md`. |
| `Pricing/` + `Support/Pricing/` | Price catalogs per provider, and the lookup and cost calculation. `.ai/rules/pricing.md`. |
| `Support/Budget/`, `Http/Middleware/` | Budget building, enforcement and the `spectra.budget` middleware. |
| `Support/Query/` | `CostQueryBuilder` / `UsageQueryBuilder` (public API) and `RequestAggregateQuery` (internal, dashboard). |
| `Actions/{Area}/`, `Http/Controllers/` | The dashboard's read side. `.ai/rules/actions.md`, `.ai/rules/controllers.md`. |
| `Integrations/` | Outside systems Spectra exports to (OpenTelemetry). |
| `Data/`, `Enums/`, `Events/`, `Exceptions/`, `Contracts/`, `Concerns/` | Shared types; `Concerns/` holds the traits users add to their models. |
| `Testing/` | `SpectraFake`, the fake users swap in with `Spectra::fake()`. |

`workbench/` is maintainer tooling (the pricing-drift command, a demo user) and is export-ignored, so nothing in `src/` may depend on it.

## One pipeline for every way in
The three tracking paths (`HttpWatcher` for Laravel `Http`, `GuzzleMiddleware` for Guzzle and the OpenAI SDK, `WithTrackingMacro` for `Http::withAITracking()`) all hand the response to `ResponseProcessor` and share stream detection through `StreamDetector`. A fix or feature for one path goes into the shared class, never into a single watcher.

## Cost has one source of truth
Every cost comes from `Support/Pricing/RequestCostCalculator::forContext()`: the unit match plus the tool-call surcharge. Don't compute a price anywhere else, not in a handler, a job or the dashboard.

## Grow folders only when needed
Create a folder when its first class arrives; no empty or `Misc/` folders. Split a folder into feature subfolders once it passes about seven files (`Actions/Costs`, `Actions/Trackables`).

## Name a service for its exact job
Name a support class for what it does (`AudioDurationExtractor`, `ApiKeyResolver`, `StreamDetector`) with no `Service` suffix; if you cannot name it precisely, split it. No `Helper`, `Util` or `Manager` grab-bags.

## Wrap every outside system in one class
Each outside API is called from one class (`OtlpExporter`, the Firecrawl scraper in `Support/Pricing/Scraping`). Everything else uses that class. Credentials and URLs come from `config('spectra.…')`, never `env()` or a literal.

## Add an interface only for a real seam
Write an interface in `Contracts/` only for a second implementation, a test fake, or an extension point users implement (`Handler`, `SpanBuilder`, `RequestExporter`, the optional `Extracts…` / `Has…` capabilities handlers opt into). Name it as a noun or capability with no `Interface` suffix, and bind the default in `SpectraServiceProvider::register()`.

## Let users replace, not patch
Anything a user may want to change is a config key or a container binding they can override: custom providers and pricing classes in `config('spectra.providers')` and `config('spectra.costs.pricing')`, a custom `integrations.request_transformer`, `SpanBuilder` or exporter. Don't make them extend an internal class to change one behaviour.

## Traits are Concerns
A trait lives in a `Concerns/` folder next to the classes that use it, is named for one capability (`MatchesEndpoints`, `ExtractsModelField`, `HasAiBudget`), and has no `Trait` suffix. If describing it needs "and", split it.

## Store to the configured connection
Every Spectra table and query uses `config('spectra.storage.connection')`, which may be a separate database from the app's. Models do this through `getConnectionName()` and migrations through `getConnection()`; never query a Spectra table through `DB::table()` on the default connection.
