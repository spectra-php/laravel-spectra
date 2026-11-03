---
paths:
  - 'src/Models/**'
  - 'src/Support/Query/**'
  - 'src/Support/Concerns/**'
  - 'database/factories/**'
---

# Models and queries

## Spectra's models live on Spectra's connection
`SpectraRequest`, `SpectraBudget`, `SpectraTag` and `SpectraDailyStat` read their connection from `config('spectra.storage.connection')` and their tables start with `spectra_`. Keep that for every new model. Ids are UUIDs (`HasUuids`); trackable and budgetable ids are strings, because users' models may use UUID, ULID or integer keys.

## Keep the @property docblock in step with the migrations
Each model lists every column as `@property` and every computed or aggregate alias as `@property-read`. When a migration adds a column, add it here in the same change, so PHPStan stays clean.

## Cast every typed column
Declare casts in `casts()`: `integer`, `boolean`, `array`, `datetime`. Type-hint dates as `CarbonInterface`, never `Illuminate\Support\Carbon`: apps may switch to `CarbonImmutable` with `Date::use()` (`ImmutableDatesTest`). Use the package's existing style (`$guarded = []`, a `$table` only where the name doesn't resolve); the Laravel 13 model attributes are not available on Laravel 12.

## Accessors derive; they don't query
Write accessors as `protected function name(): Attribute`, never `getNameAttribute()`. They only derive a value from the model's own columns: no queries.

## Models hold data, not workflows
No tracking, pricing or budget logic in a model; those belong in `Support/`. Expose state questions as predicates. Keep `request` and `response` in `$hidden` on `SpectraRequest`, so list queries and integrations stay light.

## Write SQL every driver runs
Users run SQLite, MySQL, MariaDB and PostgreSQL. Prefer the query builder; when raw SQL is needed, branch on the driver (`CostQueryBuilder::monthlyPeriodExpression()` uses `strftime` / `TO_CHAR` / `DATE_FORMAT`). Never use `EXTRACT()`, `ILIKE`, JSON operators or `::` casts without a per-driver branch. Compare dates with `whereDate()`: SQLite stores date casts as `Y-m-d 00:00:00`.

## Where queries live
One filter on one model is a scope. Dashboard aggregates go through `Support/Query/RequestAggregateQuery`. `CostQueryBuilder` and `UsageQueryBuilder` back the public `HasAiUsage` API, so their method names are public API. No repositories.

## Filter and aggregate in the database
Never `->get()` a table and filter in PHP. Select only the columns you read, aggregate in SQL, use `pluck()` for lists, and delete or update large sets in chunks (`spectra:prune` deletes in batches so a big table is not locked by one query).

## Assume the host app is strict
A host app may turn on `Model::shouldBeStrict()`. Eager-load every relation you read, select every column you later read, and never rely on lazy loading.

## Polymorphic columns store the morph class
`trackable_type` and `budgetable_type` store `$model->getMorphClass()`, so users' morph maps work. Never write `::class` there directly.
