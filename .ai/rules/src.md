---
paths:
  - 'src/**/*.php'
---

# Src

## Write for the oldest supported version
The package supports PHP 8.2 and Laravel 12 and 13 (`composer.json`). Don't use anything newer than both floors: no typed class constants or `json_validate()` (PHP 8.3), no property hooks or asymmetric visibility (8.4), no pipe operator (8.5), and no Laravel 13-only attributes (`#[Guarded]`, `#[Table]`, `#[Queue]`, `#[Tries]`). When a helper only exists in a newer Laravel, check the Laravel 12 docs before using it.

## Never break the host application
Spectra runs inside other people's apps, on their HTTP calls. A failure while tracking, pricing, persisting or exporting must never reach the user's code: run the step through a guard that calls `report($e)` and carries on (`GuzzleMiddleware::safely()`, `RequestPersister`). `OtlpExporter` logs a `Log::warning()` instead, because an unreachable collector is expected and the docs promise a warning, not an error report. Only errors the user asked for may throw: `BudgetExceededException` from a hard limit, and the user's own exception re-thrown from `Spectra::track()`.

## Check emptiness with filled() / blank()
Check for an empty value with Laravel's `filled()` / `blank()`. Never write `empty($x)`, `! empty($x)`, `$x !== ''`, `$x === ''`, `$x !== null && $x !== ''`, `$x === null || $x === ''`, `trim($x) === ''` or `is_string($x) && $x !== ''`. Read a payload key as `filled($data['key'] ?? null)`. A local array may also be compared with `=== []` / `!== []`: the result is the same as `blank()` / `filled()`, and PHPStan needs that form to see the array as non-empty afterwards (`max()`, `$items[array_key_first($items)]`). A flag that is truthy, not filled, reads as `if ($data['stream'] ?? false)`. For a value that is already typed (a `?string` property, config, request input read with `$request->string()`), `filled($x)` alone is enough. `filled()` and `blank()` treat whitespace as empty, `'0'` and `false` as filled, and a plain truthiness test (`if ($x)`) does the opposite for `'0'`, so use them in new code but don't convert an existing check whose result would change.
Exceptions, where whitespace or `'0'` is a real value: streamed text deltas and raw bytes (`StreamingTracker`, `TrackedStream`, `StreamEventParser`, binary media bodies), identifiers read from a payload (`ToolCallParser::string()` keeps `is_string($x) && $x !== ''`), and user input checked before it is validated (reviewer scores, config names). Compare those with `''` and say why in a comment. The exceptions are for new code: an existing `blank()` / `filled()` check on bytes stays, because converting it changes the result for whitespace-only bodies.

## Read provider payloads plainly; the pipeline catches bad shapes
Provider JSON is `mixed` and changes without notice. Read a string with `filled()` alone: `filled($data['model'] ?? null) ? $data['model'] : null`, `($content['type'] ?? null) === 'output_text' && filled($content['text'] ?? null)`. Don't pair it with `is_string()` just in case. `ResponseProcessor` and `StreamingTracker` run each extraction step under its own guard, so a value of the wrong type drops only that field and the request is still recorded with its cost.
Keep a type check only where a wrong type would give a wrong number, a broken loop or a `TypeError`: `is_numeric()` before a value goes into token or cost maths, `is_array()` before a `foreach` or `count()`, and `is_string()` before a value reaches a `string` parameter. An `extract…` method's `?string` return needs no check: a wrong type there drops only that field, as above. That isn't true elsewhere, so keep the check in `matchesResponse()` and the helpers it calls, in `storeMedia()` (a `TypeError` there drops the whole handler or every file), and where `null` triggers a fallback (`Provider::extractResponse()` falls back to the raw response body when a handler returns null). Don't remove an existing check either: the guard reports every `TypeError`, so removing it turns a quiet null into an error report. Where an empty string is a real answer (an empty transcript, a streamed delta), check `is_string()` alone instead of `filled()`. Never assume a key exists: read with `?? null`, `Arr::get()` or `data_get()`.

## Cast once, where an untyped value enters
Cast where a value first enters without a type: a DB aggregate alias, config, a header, a JSON key. A number from a provider payload goes through `Support\Payload` (`Payload::int($usage['prompt_tokens'] ?? null)`, `Payload::float(…, $default)`), never a bare `(int)`: under `strict_types` it must be cast, and a bare cast turns an array into 1. `Payload` always returns a number, so where an absent value must stay null, guard the cast instead: `is_numeric($x) ? (float) $x : null`. Never cast a value that already has its type: `composer analyse` runs phpstan-strict-rules' useless-cast check and fails on one. If the check is wrong because a docblock overstates the type (config a user writes, JSON object keys that decode as ints), fix the docblock and keep the cast. Never silence the check.

## Assign defaults with ??=
Write `$x ??= $y`, never `$x = $x ?? $y` or `if ($x === null) { $x = $y; }`.

## Empty, not null, for lists
A list or map that can be empty is `array $x = []`, not `?array $x = null`. Use null only when "absent" must be stored or reported differently from "empty".

## Choose boring code over clever code
Prefer an early return, `if` or `match` to nested ternaries, and drop the `else` after a `return`. More lines for more clarity is a good trade. Reach for the framework helper first, as `.ai/rules/laravel-first.md` lists.

## Build only what is needed now
Add an interface, base class, manager or wrapper only for a second real implementation, a test fake, or an extension point users need (`Contracts\SpanBuilder`, `Contracts\RequestExporter`, custom providers). A class that wraps one call is noise.

## Keep the public surface stable
Everything users can touch is public API under semver: the `Spectra` facade and `Spectra` class, `Contracts\*`, `Providers\Provider` and handlers users extend, the pricing file format, the model traits in `Concerns\` (`HasAiUsage`, `HasAiBudget`, …), events, config keys, table and column names, artisan commands and the `Http::withAITracking()` macros. Don't rename or remove any of them in a minor release; add, deprecate, and note it in `CHANGELOG.md` under "Upgrading". Keep internals `protected` or `private` so they can still change.

## Name things for what they hold and do
Variables say what they hold (`$promptTokens`, not `$data`), with no abbreviations; plural for collections, singular for one item. The usual `$e` for a caught exception and `$i` for a loop index are fine. Methods are camelCase and start with a verb, except where PHP and Laravel name them otherwise: named constructors (`from()`, `of()`, `fromArray()`), conversions (`toArray()`, `all()`), typed readers (`Payload::int()`), enum `label()` / `description()`, and fluent builder setters (`ModelDefinition`). A public or protected name that breaks this stays ("Keep the public surface stable"). Rename it only as a planned change in a major release, with a note in `CHANGELOG.md` under "Upgrading" and in `docs/upgrade.md`; a rules sweep doesn't rename. A protected method on a `final` class can't be overridden, so it may be renamed like a private one. Parameter names of public methods are public too, because callers pass arguments by name: `RequestExporter::export(array $data)` keeps `$data`. Methods and local variables that answer yes/no start with `is`, `has`, `can`, `should`, `supports` or `needs`. Constants are SCREAMING_SNAKE_CASE and always state their visibility (`protected const BILLED_FPS = 16;`); a class that only lists choices is an enum.

## Resolve through the container
Get services from the container so users can swap them: constructor injection when several methods use it, method injection for one. A singleton that depends on a binding the package rebinds at runtime resolves it with `app()` at the call instead, or it keeps the stale instance: `CostCalculator` and `RequestCostCalculator` look up `PricingLookup` per call, because a pricing file change or a config change replaces it. Provider `handlers()` build their handlers with `app(XHandler::class)`, and the service provider binds every replaceable service (`CostCalculator`, `SpanBuilder`, `RequestExporter`, …). `new` is for data and per-call state: DTOs, value objects, exceptions, and the `StreamHandler` a handler builds for each stream.

## Keep the request path cheap
Code in a watcher, `GuzzleMiddleware`, `ResponseProcessor` or a handler runs on every tracked AI call. No queries, no file reads and no outside HTTP there unless the feature needs it; cache per-process lookups (`Provider::resolvedHandlers()`), and push persistence and export to the queue or after the response when the config says so.

## No mutable static state
Spectra runs under Octane and in long-lived queue workers. Keep per-request state on the scoped `Spectra` instance or the `RequestContext`, never in a static property. A static cache may hold only values that never change, with a comment saying so. A configuration flag that the user's service provider sets once at boot, never per request, is fine too (`DashboardAssets::useDarkTheme()`). A static `WeakMap` keyed by framework objects (which `Http` factories are already watched) is fine: it holds no request data and its entries go with the objects.

## Start every Eloquent query with Model::query()
Write `SpectraRequest::query()->where(…)`, not `SpectraRequest::where(…)`. `.ai/rules/models.md` says where queries live.

## Docblocks follow Laravel; comments say why
Every class has a short docblock that says what it is and why it exists, including the provider quirk it handles (`Groq bills at least 10 seconds per request, so …`).
Every method (interfaces, constructors and `__invoke()` included) has a docblock that opens with one sentence, the way the framework and laravel/ai write them: verb first, ending with a period, in the project's terms ("Get the token usage from an OpenAI Responses API body.", "Determine if the budget allows the request.", "Create a new budget enforcer instance."). Type tags (`@param  array<string, mixed>  $data`) follow after a blank ` *` line, only where the native type isn't enough or the tag carries a description (units, what null means).
Inline comments explain why, never what the code does: if one explains what, rename or extract instead. No history, tickets or people in comments. A pricing catalog may say when and why a price changed, because that explains its `since:` and `retiredOn()` rows (`.ai/rules/pricing.md`).

## Leave formatting, refactorings and types to the tools
Pint (`laravel` preset plus `declare_strict_types`) owns code style: run `vendor/bin/pint <files>`, never format by hand. Rector (`rector.php`) owns mechanical rewrites (dead code, early returns, `Model::query()`, `today()`, `throw_if()`, the `Date` facade): run `composer refactor`, then Pint; CI fails on anything it would still change. To keep code Rector rewrites, add the rule to `withSkip()` with a comment saying why, never an inline ignore. PHPStan runs at level 8 on `src` with an empty baseline (`composer analyse`); fix errors in the code, never add a baseline entry or an `ignoreErrors` line. An inline `@phpstan-ignore <identifier>` is allowed only where the code can't express the type: an optional class from `suggest` (getID3 in `AudioDurationExtractor`), a trait method called on a user's model (`setAiBudget()` in `BudgetBuilder`), a trait only users' models use (`trait.unused` on `HasAiUsage`, `HasAiBudget`, …), and a PHP function whose output PHPStan types too loosely (`parse_str()` in `ParsesRequestData`). Anything else is fixed in the code.

## Prefer interpolation to concatenation
Write `"spectra.provider.{$provider}"`, not `'spectra.provider.'.$provider`. PHP can't interpolate constants, `__DIR__`, enum cases, function calls or static calls, so concatenating those is fine; don't add a throwaway variable just to interpolate. Byte-level strings built with `pack()` are concatenated too.
