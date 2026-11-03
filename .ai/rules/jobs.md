---
paths:
  - 'src/Jobs/**'
  - 'src/Events/**'
---

# Jobs and events

## A job only calls a service
A job's `handle()` method-injects the service that does the work and calls it (`RequestWriter::write()`, `RequestExporter::export()`). No tracking, pricing or persistence logic in the job itself, so the sync, after-response and queued paths run the same code.

## Pass plain data
The constructor takes arrays and scalars as promoted `public readonly` properties: the attributes to write, the transformed export payload. Don't pass a `RequestContext`, a service or an open stream; the job must serialise and run on another machine.

## The user's config picks the queue
Spectra's queue settings live in `config('spectra.queue')`: `enabled`, `after_response`, `connection`, `queue`, `delay`. Apply them where the job is dispatched (`RequestPersister`), never hard-code a queue, connection or delay in the job. Use only properties and traits Laravel 12 supports; no Laravel 13 queue attributes.

## A failed save never fails the user's request
Persisting and exporting run off the user's request path when configured, and report their own errors. A job that throws is retried by the user's worker, so keep `handle()` safe to repeat.

## Events are public API
`RequestTracked`, `BudgetThresholdReached` and `BudgetExceeded` are dispatched for users' listeners. Name events in the past tense with no `Event` suffix, use `Dispatchable`, and expose data as `public readonly` constructor properties. Adding a property is fine; renaming or removing one is a breaking change.

## Fire each budget alert once
`BudgetEnforcer::reportRecordedRequest()` dispatches `BudgetExceeded` (`wasBlocked: false`) once per limit type, when recorded spend crosses the limit, after the request is saved. Pre-flight blocking (`wasBlocked: true`) happens in the middleware and `enforce()`. Keep the two paths separate, and keep `BudgetAlertsOnceTest` green.
