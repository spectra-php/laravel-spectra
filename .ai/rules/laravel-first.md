---
paths:
  - 'src/**/*.php'
  - 'tests/**/*.php'
  - 'database/**'
  - 'config/**'
  - 'routes/**'
---

# Laravel first

When Laravel has a helper for the job, use it instead of the plain PHP function. The framework helpers are null-safe, read left to right, and tests can fake the facades. Convert a plain call whenever the helper gives the same result for every input the code can see. Where it doesn't (the cases listed under each heading), keep the plain call; that is not a violation. Every helper must exist in Laravel 12.0 (`.ai/rules/src.md`).

## Strings: Str
Use `Str::` for one call and `Str::of($text)` / `str($text)` for a chain: `Str::contains()`, `Str::startsWith()`, `Str::endsWith()`, `Str::lower()`, `Str::limit()`, `Str::before()` / `after()`, `Str::replace()`, `Str::substr()`, `Str::length()`. For patterns use `Str::match()`, `Str::matchAll()`, `Str::isMatch()` and `Str::replaceMatches()`, not `preg_*`. Not `str_contains`, `strtolower`, `substr`, `mb_substr`, `str_replace`, `explode` + `implode` round trips.
Keep the plain function where the helper differs:
- Byte-level code that must see raw bytes: stream parsing, binary audio, multipart request bodies, file headers built with `pack()`, and test stand-ins for binary data.
- Text whose exact bytes decide an outcome, such as the scraping safeguards' page-text matching: `Str::lower()` and `Str::length()` are multibyte and `Str::trim()` also strips Unicode whitespace, so they can change the result.
- A match that needs several capture groups at once: `Str::match()` returns only the first group, so use `preg_match()` there.

A lone `explode()` is fine; only an `explode()` + `implode()` round trip has a better helper. Plain `trim()` and `strlen()` are fine too: `Str::trim()` also strips Unicode whitespace and `Str::length()` counts characters, not bytes, so neither gives the same result.

## Arrays: collections and Arr
Chain with collections: `collect($items)->filter()->map()->values()`, never nested `array_map(array_filter(…))`. For a single step on a plain array use `Arr::` and `data_get()` for nested provider keys: `Arr::get()`, `Arr::first()`, `Arr::only()`, `Arr::except()`, `Arr::wrap()`, `Arr::pluck()`. Not `array_map`, `array_filter`, `array_column`, `array_key_exists` + manual default.
Keep a plain array where the contract asks for one (handler `array $responseData`, config, `toArray()`). When a return or property is typed `list<…>`, `array_values(array_filter(…))` is fine: `collect()->values()->all()` is typed `array<int, …>`, which PHPStan rejects there. For the same reason keep `array_map()` where PHPStan needs the array shape it keeps (`Arr::map()` returns a plain `array`). `array_merge()` and spreads have no `Arr` equivalent and are fine. Use `cursor()` / `lazyById()` for large queries.

## Files: Storage and File
Media goes to the configured disk (`Storage::disk(config('spectra.storage.media.disk'))`), never a raw path. Local files (the scraped prices file, stubs) go through `File::get()`, `File::put()`, `File::exists()`, `File::ensureDirectoryExists()`. Never `file_get_contents`, `file_put_contents`, `fopen`, `unlink`, `mkdir`, `file_exists`. Temporary files have no Laravel helper, so `tempnam()` and `sys_get_temp_dir()` are fine.

## HTTP: Http
Call outside services with `Http::` (timeouts, `retry()`, `throw()`, `->json()`), and add `withoutAITracking()` when the call goes to an AI provider. Never `curl_*`, `file_get_contents($url)` or a Guzzle client you built yourself (the OpenAI SDK's client is tracked through `GuzzleMiddleware`, not replaced). Tests use `Http::fake()`.

## URLs
`Illuminate\Support\Uri` needs `league/uri`, which Spectra does not require, and its parser rejects some URLs Guzzle accepts. So `src/` reads a URL's parts with `parse_url()`, and builds them with `url()`, `route()` and `http_build_query()`. Not string concatenation for a query string. Tests may use `Uri`.

## Time: now() and Carbon
Use `now()`, `today()` and the `Date` facade (`Date::parse()`, `Date::createFromTimestamp()`), and compare with `->isPast()`, `->diffInSeconds()`, `->subHours()`. They all honour an app's `Date::use(CarbonImmutable::class)`; `Carbon::parse()` doesn't, so don't use it. Type-hint `CarbonInterface`. Never `time()`, `date()`, `strtotime()` or `new DateTime`. Measure a new in-process duration with `hrtime()` or `Benchmark`, not `microtime()` maths. A tracked call's latency and time to first token are wall-clock differences from the call's start time (`RequestContext`, `StreamingTracker`), and so are age checks (a cache timestamp, `REQUEST_TIME_FLOAT`); those use Carbon diffs or `microtime(true)`. Wait with `Sleep::for(2)->seconds()`, never `sleep()`.

## Numbers
Most of `Number::` needs the `intl` extension, which Spectra does not require, so don't use it in `src/`. Round money with `round()` at the precision the column holds, and leave display formatting to the dashboard.

## JSON
Let Laravel encode and decode: model casts (`'json'`), `$response->json()`, `response()->json()`. When you must call `json_encode` / `json_decode` yourself, pass `JSON_THROW_ON_ERROR` and catch where the input is a provider's (a malformed body must not break tracking). The one exception is a `json_encode()` that only measures a value's size and must never fail: it uses `JSON_PARTIAL_OUTPUT_ON_ERROR`, with a comment saying why.

## Small framework helpers
Reach for these before writing the same thing by hand: `filled()` / `blank()`, `optional()` / `?->`, `value()`, `tap()`, `rescue()` instead of an empty catch-all `try`/`catch` (a `catch` for one exception type stays, because `rescue()` swallows and reports everything), `retry()` instead of a retry loop, `once()` instead of a static cache property, `throw_if()` / `throw_unless()`, `Cache::remember()` and `Cache::lock()`, `report()` and `Log::` instead of `error_log()`, `Context::` for values that follow the request onto the queue (trace ids).

## Check the docs first
Before writing a helper, search the Laravel docs (`search-docs` in Laravel Boost) for one that already exists, and check it exists in Laravel 12. A new helper goes in a class under `src/Support/`, never in a global helpers file.
