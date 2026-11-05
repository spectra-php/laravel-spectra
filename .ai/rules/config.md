---
paths:
  - 'config/**'
  - 'src/SpectraServiceProvider.php'
---

# Config

## config/spectra.php is published
Users publish the file and keep their copy, so every key is public API. Never rename or remove a key in a minor release; read the old key as a fallback and note it in `CHANGELOG.md` under "Upgrading".

## New nested keys need a default
`mergeConfigFrom()` merges only top-level keys, so a user's older published file won't have a new nested key, and its env variable would silently do nothing. Add every new nested key to `SpectraServiceProvider::NESTED_CONFIG_DEFAULTS`, or read it with a default (`config('spectra.x.y', default)`).

## Document every key in place
Each section and key gets the framework-style comment block explaining what it does and when to change it. The default is on the `env()` line below; say it in prose only when the code doesn't show it (a computed default, what `null` means). Add the key to `docs/configuration.md` too.

## Read env() inline
In `config/spectra.php`, every value read from the environment calls `env()` inline, with a `SPECTRA_` prefix (`env('SPECTRA_QUEUE_ENABLED', false)`); a value with no env variable is a plain literal. No local variables. Code anywhere else reads `config('spectra.…')`, never `env()`.

## Keys are snake_case
Keys are snake_case and grouped by area (for example `storage`, `queue`, `tracking`, `dashboard`, `costs`, `budget`, `alerts`, `watcher`, `providers`, `integrations`). Defaults keep the package safe: anything that sends data out of the app (exporters, alerts, scraping), costs money, or stores more than the tracked request itself (media files) is off by default. Recording the request, its prompt and response is the product, so it is on.
