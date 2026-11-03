---
paths:
  - 'src/Http/**'
  - 'routes/**'
---

# Controllers, routes and middleware

## Every dashboard controller is behind viewSpectra
API controllers extend `Http\Controllers\Api\BaseApiController`, whose constructor middleware denies anyone the `viewSpectra` gate refuses (`tests/Unit/ArchTest.php` checks the parent class). `DashboardController` checks the same gate. The default gate allows only the `local` environment; users redefine it. Never add a route or controller that skips the gate.

## One invokable controller per endpoint
Each controller has one `__invoke()` and is named for what it serves (`CostsController`, `ShowRequestController`, `ServeAudioController`). It reads the date range with `DateRange::fromRequest($request)`, method-injects the Actions it needs, calls them and returns a response DTO from `Data/Responses`. No queries, cost maths or formatting in the controller body.

## Return response DTOs
Answer with a `readonly` class from `Spectra\Data\Responses` built with named arguments; its snake_case properties are the JSON the Vue dashboard reads. Never return a model, a hand-built array or a `JsonResource`. Don't change a response's keys without changing the screen that reads them.

## Keep heavy columns off list endpoints
`SpectraRequest` hides `request` and `response`, so list endpoints stay light. Only the detail endpoint re-exposes them (`GetRequestDetails` uses `makeVisible(['request', 'response'])`). Search on the requests list covers `model` (LIKE) and exact `id`, `response_id` and `trace_id`, never the long-text columns.

## Serve media by stored path
Media controllers derive the mime type and extension from the stored path, and read from the disk in `config('spectra.storage.media.disk')`. Never trust a client-given extension or path.

## Register routes in routes/web.php
The service provider loads `routes/web.php` under the configured dashboard path, domain and middleware. API routes go inside the `api` prefix group; the SPA catch-all `spectra.dashboard` stays last, or it swallows every route after it.

## Middleware is user-facing API
`spectra.budget` (`EnforceBudgetLimit`) and `spectra.trace` (`ContinueTrace`) are aliases users put on their own routes. Keep their names and parameters stable, and document a change in `docs/budgets.md` or `docs/opentelemetry.md`.
