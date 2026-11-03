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
Each section and key gets the framework-style comment block explaining what it does, its default and when to change it. Add the key to `docs/configuration.md` too.

## Read env() inline
In `config/spectra.php`, call `env()` inline for each value, with a `SPECTRA_` prefix (`env('SPECTRA_QUEUE_ENABLED', false)`). No local variables. Code anywhere else reads `config('spectra.…')`, never `env()`.

## Keys are snake_case
Keys are snake_case and grouped by area (`storage`, `queue`, `tracking`, `dashboard`, `costs`, `budget`, `watcher`, `providers`, `integrations`). Defaults keep the package safe: off for anything that stores more data, sends data out or costs money.
