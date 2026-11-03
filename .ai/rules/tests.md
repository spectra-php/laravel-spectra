---
paths:
  - 'tests/**'
  - 'database/factories/**'
---

# Tests

## What earns a test
Test what users get: the stored row, its cost, the budget decision, the dashboard numbers. Every provider endpoint has a test that tracks a real fixture and checks the provider, model, model type, metrics and a non-zero cost. Add a unit test for logic with real branches (handler resolution, usage parsing, stream decoding, price lookup, tier and long-context selection, scraping safeguards), one case per branch.

Don't test labels and copy, the exact shape of a DTO, config defaults, enum lists, service-provider bindings, or framework behaviour. A dataset holds distinct branches only.

## Pest style
Write `it('bills groq whisper at the 10-second minimum', function () { … })` with `expect()` chains joined by `->and()`. No `test()`, no PHPUnit classes, no `$this->assertX()`. Start a new file with a short docblock that says which behaviour it covers. Use `describe()` only when one file covers several subjects.

## Names state the scenario and the outcome
A test name says what happens and what should result, never "works". Split a test when "and" joins two separate behaviours.

## Put the file where it belongs
`tests/Feature/Providers/{Provider}…Test.php` tracks provider responses end to end through `Spectra` or the watchers. `tests/Feature/Tracking/` covers the pipeline across providers (async jobs, tags, traces), and the other `tests/Feature/*Test.php` files cover commands, budgets and the facade. `tests/Unit/{Area}/` calls one class directly: `Providers`, `Pricing`, `Tracking`, `Telemetry`, `Queries`, `Controllers`.

## Fixtures over hand-built payloads
Load provider responses with `$this->loadMockResponse('{provider}/{file}.json')` from `tests/responses/`. Add a new fixture from a real response rather than typing one, and show the price maths in a comment next to the cost assertion (`// 0.185 cents/minute × (120.5s / 60)`). Compare costs with `toEqualWithDelta()`.

## Never call a real outside service
Fake every HTTP call with `Http::fake([...])` or a Guzzle `MockHandler`. Firecrawl, OTLP endpoints and provider APIs are never reached from tests.

## Arrange, act, assert
Keep the three parts as blocks separated by blank lines, with no `// Arrange` comments. Assert only what this behaviour changes, not a whole `toArray()`.

## Database setup is the TestCase's job
`tests/TestCase.php` runs the package migrations on Testbench's in-memory SQLite `testing` connection and creates a `users` table. Add a new migration to its list. Use `SpectraRequest::factory()` and the `Workbench\App\Models\User` model for trackables and budgets; create only the rows the test needs.

## Lock dashboard numbers on purpose
`tests/Unit/Controllers/DashboardCharacterizationTest` pins the stats and trackable numbers. A change that moves them must be deliberate: update the expected values in the same change and say why.

## Architecture tests
Structural rules live in `tests/Unit/ArchTest.php` as Pest `arch()` expectations (readonly DTOs, invokable Actions, controllers extending `BaseApiController`, no debug calls). Add a new structural rule there.

## Time
Move time with `$this->travelTo()` or `$this->freezeTime()` in new tests; they reset on their own, unlike the older `Carbon::setTestNow()` calls. A test about calendar periods must pass on every day of the month (the 31st, the 1st, February).

## Run the checks
Run `vendor/bin/pest <files>` while working, then the whole suite, `composer analyse` and `vendor/bin/pint` before you finish.
