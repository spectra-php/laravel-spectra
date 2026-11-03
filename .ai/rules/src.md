---
paths:
  - 'src/**/*.php'
---

# Src

## Write for the oldest supported version
The package supports PHP 8.2 and Laravel 12 and 13 (`composer.json`). Don't use anything newer than both floors: no typed class constants or `json_validate()` (PHP 8.3), no property hooks or asymmetric visibility (8.4), no pipe operator (8.5), and no Laravel 13-only attributes (`#[Guarded]`, `#[Table]`, `#[Queue]`, `#[Tries]`). When a helper only exists in a newer Laravel, check the Laravel 12 docs before using it.

## Never break the host application
Spectra runs inside other people's apps, on their HTTP calls. A failure while tracking, pricing, persisting or exporting must never reach the user's code: run the step through a guard that calls `report($e)` and carries on (`GuzzleMiddleware::safely()`, `RequestPersister`). Only errors the user asked for may throw: `BudgetExceededException` from a hard limit, and the user's own exception re-thrown from `Spectra::track()`.

## Check provider payloads by type, emptiness with filled() / blank()
Provider JSON is `mixed` and changes without notice, so type-check every value you read from it: `is_array($data['usage'] ?? null)`, `is_numeric(…)`, `is_string($id) && $id !== ''`, then cast. Never assume a key exists or has the right type. For values that are already typed (config, DTO fields, request input), check emptiness with `filled()` / `blank()`.

## Choose boring code over clever code
Prefer an early return, `if` or `match` to nested ternaries, and drop the `else` after a `return`. More lines for more clarity is a good trade. Reach for the framework helper first, as `.ai/rules/laravel-first.md` lists.

## Build only what is needed now
Add an interface, base class, manager or wrapper only for a second real implementation, a test fake, or an extension point users need (`Contracts\SpanBuilder`, `Contracts\RequestExporter`, custom providers). A class that wraps one call is noise.

## Keep the public surface stable
Everything users can touch is public API under semver: the `Spectra` facade and `Spectra` class, `Contracts\*`, `Providers\Provider` and handlers users extend, `Pricing\ProviderPricing` and `ModelDefinition`, the model traits in `Concerns\` (`HasAiUsage`, `HasAiBudget`, …), events, config keys, table and column names, artisan commands and the `Http::withAITracking()` macros. Don't rename or remove any of them in a minor release; add, deprecate, and note it in `CHANGELOG.md` under "Upgrading". Keep internals `protected` or `private` so they can still change.

## Name things for what they hold and do
Variables say what they hold (`$promptTokens`, not `$data`), with no abbreviations; plural for collections, singular for one item. Methods are camelCase and start with a verb. Methods and local variables that answer yes/no start with `is`, `has`, `can`, `should`, `supports` or `needs`. Constants are SCREAMING_SNAKE_CASE and always state their visibility (`protected const BILLED_FPS = 16;`); a class that only lists choices is an enum.

## Resolve through the container
Get services from the container so users can swap them: constructor injection when several methods use it, method injection for one. Provider `handlers()` build their handlers with `app(XHandler::class)`, and the service provider binds every replaceable service (`CostCalculator`, `SpanBuilder`, `RequestExporter`, …). `new` is for data: DTOs, value objects, exceptions.

## Keep the request path cheap
Code in a watcher, `GuzzleMiddleware`, `ResponseProcessor` or a handler runs on every tracked AI call. No queries, no file reads and no outside HTTP there unless the feature needs it; cache per-process lookups (`Provider::resolvedHandlers()`), and push persistence and export to the queue or after the response when the config says so.

## No mutable static state
Spectra runs under Octane and in long-lived queue workers. Keep per-request state on the scoped `Spectra` instance or the `RequestContext`, never in a static property. A static cache may hold only values that never change, with a comment saying so.

## Start every Eloquent query with Model::query()
Write `SpectraRequest::query()->where(…)`, not `SpectraRequest::where(…)`. `.ai/rules/models.md` says where queries live.

## Comments say why
Every class has a short docblock that says what it is and why it exists, including the provider quirk it handles (`Groq bills at least 10 seconds per request, so …`). Comments explain why, never what the code does: if a comment explains what, rename or extract instead. No history, tickets or people in comments.

## Leave formatting and types to the tools
Pint (`laravel` preset plus `declare_strict_types`) owns code style: run `vendor/bin/pint <files>`, never format by hand. PHPStan runs at level 8 on `src` with an empty baseline (`composer analyse`); fix errors in the code, never add a baseline entry or an `ignoreErrors` line.

## Prefer interpolation to concatenation
Write `"spectra.provider.{$provider}"`, not `'spectra.provider.'.$provider`.
