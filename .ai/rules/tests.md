---
paths:
  - 'tests/**/*.php'
  - 'database/factories/**'
---

# Tests

## What earns a test
Test what users get: the stored row, its cost, the budget decision, the dashboard numbers. Every provider endpoint has a test that tracks a real fixture and checks the provider, model, model type, metrics and a non-zero cost. Add a unit test for logic with real branches (handler resolution, usage parsing, stream decoding, price lookup, tier and long-context selection, scraping safeguards), one case per branch.

Don't test labels and copy, the exact shape of a DTO, config defaults, enum lists, service-provider bindings, or framework behaviour. A dataset holds distinct branches only.

## Pest style
Write `it('bills groq whisper at the 10-second minimum', function () { … })` with `expect()` chains joined by `->and()`; Pest's `->throws()` is fine too. No `test()`, no PHPUnit classes, no `$this->assertX()` or static `PHPUnit\Framework\Assert` calls. Every test file has a short docblock before its first hook or test (usually right after the imports) that says which behaviour it covers; helper traits in `tests/Concerns/` follow the class docblock rule in `.ai/rules/src.md` instead. Use `describe()` only when one file covers several subjects, or to give a group of tests their own `beforeEach()`.

## Names state the scenario and the outcome
A test name says what happens and what should result, never "works". Split a test when "and" joins two separate behaviours.

## Put the file where it belongs
`tests/Feature/Providers/{Provider}…Test.php` tracks provider responses end to end through `Spectra` or the watchers. `tests/Feature/Tracking/` covers the pipeline across providers (async jobs, tags, traces), and the other `tests/Feature/*Test.php` files cover commands, budgets, the facade and each dashboard or observability feature (scores, evals, review queues, errors, redaction). `tests/Unit/{Area}/` calls one class directly: `Providers`, `Pricing`, `Tracking`, `Telemetry`, `Queries`, `Controllers`. The area follows what the class is for, not its namespace: `Support\Payload`, `Support\MessageTextExtractor` and `Support\ProviderRegistry` are tested under `Providers`, `Support\RequestTransformer` under `Telemetry`. A unit test for a class outside those areas (an enum, a DTO, the migrations, other `Support` classes) and `ArchTest.php` sit in `tests/Unit/` itself.

## Fixtures over hand-built payloads
Load provider responses with `$this->loadMockResponse('{provider}/{file}.json')` from `tests/responses/`. Add a new fixture from a real response rather than typing one. A unit test of one branch (a usage shape, a stream event, `matchesResponse()`) may build the small payload inline, and a variant of a real response is derived from its loaded fixture. Show the price maths in a comment next to the cost assertion (`// 0.185 cents/minute × (120.5s / 60)`). Compare costs with `toEqualWithDelta()`, except where the test exists to prove a cost comes back as a `float` (aggregate casts, sub-cent precision): `toBe(12.5)` checks the type, which a delta does not. Compare a stored JSON object (`metadata`, `attributes`, `tool_call_counts`) with `toEqual()`, not `toBe()`: MySQL's JSON type gives the keys back in its own order, so `toBe()` passes on SQLite and fails on MySQL. A JSON list (`allowed_providers`) keeps its order and may use `toBe()`.

## Never call a real outside service
Fake every HTTP call with `Http::fake([...])` or a Guzzle `MockHandler`. Firecrawl, OTLP endpoints and provider APIs are never reached from tests.

## Arrange, act, assert
Keep the three parts as blocks separated by blank lines, with no `// Arrange` comments. One part may itself span more than one block. Assert only what this behaviour changes, not a whole `toArray()`.

## Database setup is the TestCase's job
`tests/TestCase.php` runs the package migrations on Testbench's in-memory SQLite `testing` connection and creates a `users` table. Add a new migration to its list. Use `SpectraRequest::factory()` and the `Workbench\App\Models\User` model for trackables and budgets; create only the rows the test needs.

## Lock dashboard numbers on purpose
`tests/Unit/Controllers/DashboardCharacterizationTest` pins the stats and trackable numbers. A change that moves them must be deliberate: update the expected values in the same change and say why.

## Architecture tests
Structural rules live in `tests/Unit/ArchTest.php` as Pest `arch()` expectations (readonly DTOs, invokable Actions, controllers extending `BaseApiController`, no debug calls). Add a new structural rule there.

## Time
Move time with `$this->travelTo()` or `$this->freezeTime()` in tests you write or change; they reset on their own, unlike the older `Carbon::setTestNow()` calls. A file-level helper function has no `$this`, so it uses `Date::setTestNow()`. A test about calendar periods must pass on every day of the month (the 31st, the 1st, February).

## Run the checks
Run `vendor/bin/pest <files>` while working, then the whole suite, `composer analyse` and `vendor/bin/pint` before you finish. The suite runs on in-memory SQLite by default, which hides missing columns, column types and lengths; when a change touches a migration, raw SQL or a length-bounded string, run the affected files on MySQL and PostgreSQL too, as CI does: `SPECTRA_TEST_DB_DRIVER=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=… DB_USERNAME=… DB_PASSWORD=… vendor/bin/pest <files>` (`mysql` likewise). `tests/TestCase.php` empties the tables before each test and rebuilds the schema after a test that changed it.
