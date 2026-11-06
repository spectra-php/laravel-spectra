---
paths:
  - 'src/Actions/**'
---

# Actions

## Actions are the dashboard's read side
`src/Actions/{Area}/` holds the queries and shaping behind the dashboard API, one class per answer the dashboard needs (`GetCostsByProvider`, `GetTrackableStats`, `ResolveTrackableMetadata`), plus the maintenance operations its screens and commands run (`PruneEntries`, `RebuildStats`, `GroupErrors`). Tracking, pricing and budgets are not Actions; they live in `Support/`.

## One class, one __invoke()
An Action is invokable: its one public method is `__invoke()` (`tests/Unit/ArchTest.php` checks that it has `__invoke()`, not that it is the only public method). A few older Actions also expose public helpers their callers use (`GetAnnotationQueues`, `GetEvaluations`, `PruneEntries`, `RebuildStats`, `ScrapeProviderPrices`); don't add more, and move a helper out when its code changes anyway. Name it verb first: a read for what it returns (`Get…` for a query, `Format…` for shaping rows, `Resolve…` for lookups, `Build…` for assembling a breakdown), a maintenance operation for what it does (`PruneEntries`, `PreviewPrune`, `RebuildStats`).

## Take typed input; return a typed result
Take a `DateRange`, ids, or the `Request` when the Action reads the dashboard's filters. The controller reads the date range and required inputs and answers a missing one with an HTTP error; an Action never calls `abort()`. Return a DTO from `Spectra\Data`, a `Collection<int, XData>`, a paginator, a scalar, or an array; give collections and arrays a generic or shape docblock (`@return Collection<int, CostByProvider>`, `@return list<array{name: string, count: int}>`). Never return an HTTP response, except a media Action that streams the file (`ResolveVideo`, `StreamOpenAiVideo`).

## Aggregate with RequestAggregateQuery
Build dashboard aggregates over `spectra_requests` on `Support/Query/RequestAggregateQuery` (`make($dateRange)`, or `fromRollups($dateRange)` for anything the daily stats hold, which is what keeps a screen fast on a large table; `successfulOnly()`, `forTrackable()`, `successfulCost()`, `byDate()`, `groupByColumns()`, …). Add a method there instead of repeating SQL in an Action; a new aggregate selects through its protected `aggregate($expression, $alias, $cast)`, so the alias comes back typed on every driver. Actions and row DTOs read those aliases as they are and never cast them again (`AggregateCastsTest` locks the casts). An aggregate only one Action needs may add its own `selectRaw()` on `toQuery()->toBase()` and cast that alias once where it is read; when a second Action needs it, move it into `RequestAggregateQuery`. Aggregates over other Spectra tables (scores, review items, dataset examples) start from that model's `query()` inside the Action, with the same success convention where it applies. Don't bring back a `Queries/` folder.

## Counts include failures; cost and latency don't
Request counts include failed requests. Cost and latency aggregate over successful requests only, through `AggregatesSuccessfulRequests`. The one rule is `SpectraRequest::SUCCESSFUL_SQL` / `FAILED_SQL` and the `successful()` / `failed()` scopes: a request is successful when its status is 200–299 and `error_type` is null, and failed otherwise (a status outside 2xx, none, or an error recorded on a 2xx answer, such as a provider-reported job failure or a stream that broke off), so success plus failed always equals total. Never spell the condition out in an Action; use the scopes, the trait's expressions or the constants.

## Group by the polymorphic pair
Per-user numbers group by `(trackable_type, trackable_id)`: ids collide across types. Enrich names afterwards with `ResolveTrackableMetadata`.

## Validate sort and filter input
Read sort columns, sort directions and filter names against an allow-list (`in_array($request->input('sort_by'), $sortable)`); never pass a raw query parameter as a column name, a direction or raw SQL to `orderBy()`, `where()` or `selectRaw()`. Search text and ids are values, not columns: pass them as bound values (`where('model', 'like', "%{$search}%")`).

## Compose through the constructor
An Action that needs a service or another Action takes it as a promoted `private readonly` constructor parameter. Never `new` or `app()` one inside an Action.

## Test through the controller
The controller tests in `tests/Unit/Controllers` cover Actions. When an aggregate changes, `DashboardCharacterizationTest` must still pass, or be updated on purpose.
