---
paths:
  - 'src/Data/**'
---

# Data transfer objects

## Use a DTO where data crosses a boundary
Use a DTO for data that moves between parts of the package: a handler's metrics, a budget status, an Action's result, a dashboard response. Skip it for a single model or two values. The package has no `spatie/laravel-data`; don't add it.

## Every DTO is a readonly DataTransferObject
Write `readonly class X extends DataTransferObject` (`tests/Unit/ArchTest.php` checks every class in `Spectra\Data` is readonly). The base class gives `toArray()`, JSON and array access. DTOs never change after they are built; to change a value, build a new one.

## Put each DTO in the right folder
Dashboard answers go in `Data/Responses` with a `…Response` suffix (`CostsResponse`, `RequestDetailsResponse`). Everything else sits flat in `Data/`, named for what it holds: metrics (`TokenMetrics`, `AudioMetrics`), rows (`CostByProvider`, `TopModel`) and budget values (`BudgetStatus`, `RemainingBudget`).

## Property names follow the wire
Internal DTOs (metrics, budgets, and Action results that never become JSON, such as `MediaContent`) use camelCase properties. Dashboard responses and the row DTOs inside them use snake_case, because their properties are the JSON keys the Vue screens read. Never mix both in one class, and never add a name mapper.

## Build with named arguments and named constructors
Create DTOs with named arguments: `new CostByProvider(provider: …, cost: …)`. A DTO built from another source gets a static constructor named for it: `fromCollection()`, `fromArray()`, `fromModel()`. `fromArray()` on provider data accepts every key spelling handlers meet (`prompt_tokens` or `input_tokens`).

## Type every property
Make a property nullable only when the value can really be null, and nest DTOs instead of flattening fields. Internal DTOs use the enum type for enum values; snake_case wire DTOs hold the enum's `->value` and `label()` as strings, because their properties are the JSON the screens read. Document collections as `Collection<int, XData>` and arrays with a generic type. Money is `float` cents (sub-cent precision matters); never truncate it to `int`. Budget limits and the spend compared with them are the exception: they are whole `int` cents, because limits are set in whole cents and recorded spend rounds up (`BudgetEnforcer::toWholeCents()`).

## Metrics hold totals, not overlaps
`Metrics` carries one optional part per kind of usage (`tokens`, `image`, `audio`, `video`, `search`, `document`). In `TokenMetrics`, `promptTokens` and `completionTokens` are totals, and every other field is a breakdown already inside one of them, so nothing is counted twice. A new field follows the same rule and says which total it belongs to.
