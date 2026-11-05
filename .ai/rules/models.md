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
Each model lists every column as `@property`, and every accessor and `withCount()` alias as `@property-read`. When a migration adds a column, add it here in the same change, so PHPStan stays clean.

## Cast every typed column
Declare casts in `casts()`: `integer`, `boolean`, `json` (the package's spelling of `array`), `datetime`. Type-hint dates as `CarbonInterface`, never `Illuminate\Support\Carbon`: apps may switch to `CarbonImmutable` with `Date::use()` (`ImmutableDatesTest`). Use the package's existing style: `$guarded = []` (`SpectraTag` keeps `$fillable = ['name']`, so tags can only be created by name), and no `$table`: name the model so Laravel's inferred table is the `spectra_` one (`SpectraRequest` → `spectra_requests`). The Laravel 13 model attributes are not available on Laravel 12.

## Accessors derive; they don't query
Write accessors and mutators as `protected function name(): Attribute`, never `getNameAttribute()` / `setNameAttribute()`. They only derive a value from the model's own columns: no queries. The public `get…Attribute()` / `set…Attribute()` methods on `SpectraBudget`, `SpectraDailyStat` and `SpectraTag` are public API and stay, as `.ai/rules/src.md` § "Name things for what they hold and do" says for other public names.

## Models hold data, not workflows
No tracking, pricing or budget logic in a model; those belong in `Support/`. `SpectraDailyStat`'s public static `record()`, `accumulate()` and aggregate readers predate this and are public API; don't add more. Expose state questions as predicates. Keep `request` and `response` in `$hidden` on `SpectraRequest`, so list queries and integrations stay light.

## Write SQL every driver runs
Users run SQLite, MySQL, MariaDB and PostgreSQL. Prefer the query builder; when raw SQL is needed, branch on the driver (`CostQueryBuilder::monthlyPeriodExpression()` uses `strftime` / `TO_CHAR` / `DATE_FORMAT`). Never use `EXTRACT()`, `ILIKE`, JSON operators or `::` casts without a per-driver branch. Compare dates with `whereDate()`: SQLite stores date casts as `Y-m-d 00:00:00`.

## Where queries live
One filter on one model is a scope. Dashboard aggregates go through `Support/Query/RequestAggregateQuery`. `CostQueryBuilder` and `UsageQueryBuilder` back the public `Spectra::costs()` / `Spectra::usage()` API, so their method names are public API. No repositories.

## An aggregate alias never shares a name with an accessor
Reading an alias through a model runs the accessor of the same name instead: `SUM(prompt_tokens + completion_tokens) as total_tokens` on `SpectraRequest` runs its `totalTokens()` accessor, which adds two columns the query never selected and returns 0. Name aliases for what they sum (`tokens_sum`, as `RequestAggregateQuery`, `UsageQueryBuilder` and `HasAiUsageStats` do), and map them to the public key (`total_tokens`) when you build the result. Aggregating on `toBase()` avoids accessors too.

## Filter and aggregate in the database
Never `->get()` a table and filter in PHP. Select only the columns you read, aggregate in SQL, use `pluck()` for lists, and delete or update large sets in chunks (`spectra:prune` deletes in batches so a big table is not locked by one query).

## Assume the host app is strict
A host app may turn on `Model::shouldBeStrict()`. Eager-load every relation you read, select every column you later read, and never rely on lazy loading.

## Polymorphic columns store the morph class
`trackable_type` and `budgetable_type` store `$model->getMorphClass()`, so users' morph maps work. Never write `::class` there directly.
