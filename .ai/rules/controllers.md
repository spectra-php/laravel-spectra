---
paths:
  - 'src/Http/**'
  - 'routes/**'
---

# Controllers, routes and middleware

## Every dashboard controller is behind viewSpectra
API controllers extend `Http\Controllers\Api\BaseApiController`, whose constructor middleware denies anyone the `viewSpectra` gate refuses (`tests/Unit/ArchTest.php` checks the parent class). `DashboardController` checks the same gate. The default gate allows only the `local` environment; users redefine it. Never add a route or controller that skips the gate.

The one exception is the page a shared [dashboard](docs/dashboards.md)'s viewer opens: `routes/dashboards.php` and `Http\Controllers\Dashboards\`, under their own `dashboards.middleware`, because viewers aren't dashboard users. Each of these controllers checks the viewer against the saved dashboard through `DashboardTrackableResolver` and fails closed (no user, no trackable, or a trackable the dashboard isn't for is a 403). The scope comes only from the saved dashboard and the viewer, never from the request, and everything they answer goes through `BuildDashboard`, whose DTOs hold a fixed list of fields: no prompt, response, metadata, id or trace, and no cost on a dashboard that hides costs. Add nothing else there.

## One invokable controller per endpoint
Each controller has one `__invoke()` and is named for what it serves (`CostsController`, `ShowRequestController`, `ServeAudioController`). It reads the date range with `DateRange::fromRequest($request)`, method-injects the Actions it needs, calls them and returns a response DTO from `Data/Responses`. A write endpoint, or a read whose answer a `Support` service already gives, method-injects that service instead (`AnnotationQueues`, `RecordingSwitch`, `AlertMonitor`). No queries, cost maths or formatting in the controller body; loading the one model the endpoint is about (`SpectraRequest::query()->findOrFail($id)`) to hand it to an Action is fine.

## Return response DTOs
Answer with a `readonly` class from `Spectra\Data\Responses` built with named arguments; its snake_case properties are the JSON the Vue dashboard reads. Never return a model, a hand-built array or a `JsonResource`. Two exceptions: write endpoints (prune, pause recording, review an item) answer with a small `JsonResponse` of snake_case keys, and media endpoints return the file or stream response ("Serve media by stored path"). Don't change a response's keys without changing the screen that reads them.

## Keep heavy columns off list endpoints
`SpectraRequest` hides `request` and `response`, so list endpoints stay light. Only endpoints that show one call in full re-expose them: the request details (`GetRequestDetails` uses `makeVisible(['request', 'response'])`), the next review item and a dataset example. Search on the requests list covers `model` (LIKE) and exact `id`, `response_id` and `trace_id`, never the long-text columns.

## Serve media by stored path
Media controllers derive the mime type and extension from the stored path, and read from the disk in `config('spectra.storage.media.disk')`. Never trust a client-given extension or path.

## Register routes in routes/web.php
The service provider loads `routes/web.php` under the configured dashboard path, domain and middleware. API routes go inside the `api` prefix group; the SPA catch-all `spectra.dashboard` stays last, or it swallows every route after it.

## Middleware is user-facing API
`spectra.budget` (`EnforceBudgetLimit`) and `spectra.trace` (`ContinueTrace`) are aliases users put on their own routes. Keep their names and parameters stable, and document a change in `docs/budgets.md` or `docs/opentelemetry.md`.
