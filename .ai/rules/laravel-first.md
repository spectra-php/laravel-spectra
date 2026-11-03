---
paths:
  - 'src/**/*.php'
  - 'tests/**'
  - 'database/**'
  - 'config/**'
  - 'routes/**'
---

# Laravel first

When Laravel has a helper for the job, use it instead of the plain PHP function. The framework helpers are null-safe, read left to right, and tests can fake the facades. Older code still has plain PHP calls; do not copy them, and convert the ones in lines you touch. Every helper must exist in Laravel 12 (`.ai/rules/src.md`).

## Strings: Str
Use `Str::` for one call and `Str::of($text)` / `str($text)` for a chain: `Str::contains()`, `Str::startsWith()`, `Str::endsWith()`, `Str::lower()`, `Str::limit()`, `Str::before()` / `after()`, `Str::replace()`, `Str::substr()`, `Str::length()`. For patterns use `Str::match()`, `Str::matchAll()`, `Str::isMatch()` and `Str::replaceMatches()`, not `preg_*`. Not `str_contains`, `strtolower`, `substr`, `mb_substr`, `str_replace`, `explode` + `implode` round trips.
Exception: byte-level code that must see raw bytes (stream parsing, binary audio).

## Arrays: collections and Arr
Chain with collections: `collect($items)->filter()->map()->values()`, never nested `array_map(array_filter(…))`. For a single step on a plain array use `Arr::` and `data_get()` for nested provider keys: `Arr::get()`, `Arr::first()`, `Arr::only()`, `Arr::except()`, `Arr::wrap()`, `Arr::pluck()`. Not `array_map`, `array_filter`, `array_column`, `array_key_exists` + manual default.
Keep a plain array where the contract asks for one (handler `array $responseData`, config, `toArray()`). Use `cursor()` / `lazyById()` for large queries.

## Files: Storage and File
Media goes to the configured disk (`Storage::disk(config('spectra.storage.media.disk'))`), never a raw path. Local files (the scraped prices file, stubs) go through `File::get()`, `File::put()`, `File::exists()`, `File::ensureDirectoryExists()`. Never `file_get_contents`, `file_put_contents`, `fopen`, `unlink`, `mkdir`, `file_exists`.

## HTTP: Http
Call outside services with `Http::` (timeouts, `retry()`, `throw()`, `->json()`), and add `withoutAITracking()` when the call goes to an AI provider. Never `curl_*`, `file_get_contents($url)` or a Guzzle client you built yourself (the OpenAI SDK's client is tracked through `GuzzleMiddleware`, not replaced). Tests use `Http::fake()`.

## URLs
Read and build URLs with `Illuminate\Support\Uri` (`Uri::of($url)->host()`, `->path()`, `->withQuery()`), `url()` and `route()`. Not `parse_url`, `http_build_query` or string concatenation.

## Time: now() and Carbon
Use `now()`, `today()`, `Carbon::parse()`, and compare with `->isPast()`, `->diffInSeconds()`, `->subHours()`. Type-hint `CarbonInterface`. Never `time()`, `date()`, `strtotime()` or `new DateTime`. Measure latency with `hrtime()` or `Benchmark`, not `microtime()` maths. Wait with `Sleep::for(2)->seconds()`, never `sleep()`.

## Numbers
Most of `Number::` needs the `intl` extension, which Spectra does not require, so don't use it in `src/`. Round money with `round()` at the precision the column holds, and leave display formatting to the dashboard.

## JSON
Let Laravel encode and decode: model casts (`'array'`), `$response->json()`, `response()->json()`. When you must call `json_encode` / `json_decode` yourself, pass `JSON_THROW_ON_ERROR` and catch where the input is a provider's (a malformed body must not break tracking).

## Small framework helpers
Reach for these before writing the same thing by hand: `filled()` / `blank()`, `optional()` / `?->`, `value()`, `tap()`, `rescue()` instead of an empty `try`/`catch`, `retry()` instead of a retry loop, `once()` instead of a static cache property, `throw_if()` / `throw_unless()`, `Cache::remember()` and `Cache::lock()`, `report()` and `Log::` instead of `error_log()`, `Context::` for values that follow the request onto the queue (trace ids).

## Check the docs first
Before writing a helper, search the Laravel docs (`search-docs` in Laravel Boost) for one that already exists, and check it exists in Laravel 12. A new helper goes in a class under `src/Support/`, never in a global helpers file.
