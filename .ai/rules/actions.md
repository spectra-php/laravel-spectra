---
paths:
  - 'src/Actions/**'
---

# Actions

## Actions are the dashboard's read side
`src/Actions/{Area}/` holds the queries and shaping behind the dashboard API: one class per answer the dashboard needs (`GetCostsByProvider`, `GetTrackableStats`, `ResolveTrackableMetadata`). Tracking, pricing and budgets are not Actions; they live in `Support/`.

## One class, one __invoke()
An Action is invokable: its one public method is `__invoke()` (`tests/Unit/ArchTest.php` enforces it). Name it verb first for what it returns: `Get…` for a query, `Format…` for shaping rows, `Resolve…` for lookups, `Build…` for assembling a breakdown.

## Take typed input; return a typed result
Take a `DateRange`, ids, or the `Request` when the Action reads the dashboard's filters. Return a DTO from `Spectra\Data`, a `Collection<int, XData>`, a paginator or a scalar; give collections and arrays a generic docblock (`@return Collection<int, CostByProvider>`). Never return an HTTP response.

## Aggregate with RequestAggregateQuery
Build dashboard aggregates on `Support/Query/RequestAggregateQuery` (`make($dateRange)`, `successfulOnly()`, `forTrackable()`, `successfulCost()`, `byDate()`, `groupByColumns()`, …). Add a method there instead of repeating SQL in an Action. Don't bring back a `Queries/` folder.

## Counts include failures; cost and latency don't
Request counts include failed requests. Cost and latency aggregate over 2xx only, through `AggregatesSuccessfulRequests`. A failed request is any status outside 200–299, or null, so success plus failed always equals total. Keep every new aggregate on this convention.

## Group by the polymorphic pair
Per-user numbers group by `(trackable_type, trackable_id)`: ids collide across types. Enrich names afterwards with `ResolveTrackableMetadata`.

## Validate sort and filter input
Read sort columns and filters against an allow-list (`in_array($request->input('sort_by'), $sortable)`); never pass a raw query parameter to `orderBy()`, `where()` or `selectRaw()`.

## Compose through the constructor
An Action that needs a service or another Action takes it as a promoted `private readonly` constructor parameter. Never `new` or `app()` one inside an Action.

## Test through the controller
The controller tests in `tests/Unit/Controllers` cover Actions. When an aggregate changes, `DashboardCharacterizationTest` must still pass, or be updated on purpose.
