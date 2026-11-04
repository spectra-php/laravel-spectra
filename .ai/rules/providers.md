---
paths:
  - 'src/Providers/**'
  - 'src/Concerns/**'
  - 'src/Contracts/**'
  - 'src/Watchers/**'
  - 'src/Support/Tracking/**'
  - 'tests/responses/**'
---

# Providers and handlers

## A provider names hosts; handlers own extraction
A provider class (`Providers/{Provider}/{Provider}.php`, extending `Provider`) returns its slug, its hosts and its handlers, plus provider-wide quirks (response id location, pricing tier, `normalizeEndpoint()`). Everything about one endpoint (its paths, model type, metrics, model, response text, finish reason) belongs to a handler in `Handlers/`, implementing `Contracts\Handler` and opting into the `Extracts…` / `Has…` contracts it needs. Streaming lives in `Streaming/`.

## List specialists after the generic handler
When several handlers match the same endpoint, `Provider::resolveHandler()` walks `handlers()` in reverse and takes the first whose `matchesResponse()` accepts the body. So a specialist (a narrow response shape, like an image response on a chat endpoint) comes after the generic handler it specializes, and its `matchesResponse()` checks for a key only that shape has. Getting the order wrong silently records the request as the wrong type; add a test with a fixture of the specialist's response.

## Normalise usage to Spectra's shape
Every handler converts the provider's usage into `Metrics`: `TokenMetrics` totals (input includes cache reads and writes; output includes reasoning), plus `ImageMetrics`, `AudioMetrics`, `VideoMetrics`, `SearchMetrics` or `DocumentMetrics` for per-unit billing. Record what the provider bills (Groq's 10-second transcription minimum, fal's billed frames), and say why in the class docblock. When the provider reports its own cost, put it in `reportedCostInCents`.

## Read the payload defensively
Provider responses change without notice. Never assume a key exists (`?? null`, `data_get()`), default to zero or null, and never throw from a handler: an unknown shape must still record the request, with whatever it could read. Type-check only where a wrong type gives a wrong number or a broken loop (`is_numeric()` before maths, `is_array()` before a `foreach`), as `.ai/rules/src.md` § "Read provider payloads plainly" says; the pipeline's guards catch the rest.

## Every catalog model is reachable
Each model in a provider's pricing class must be reachable through one of its handlers, with an endpoint and metrics that produce a non-zero cost. Adding a priced model type (embeddings, rerank, speech, transcription) means adding its handler, a fixture and a feature test that asserts the cost end to end.

## Reuse the OpenAI-compatible base
Groq, Mistral, xAI, OpenRouter and other OpenAI-style chat APIs extend `Providers/Concerns/OpenAiCompatibleChatHandler` and `OpenAiCompatibleStreaming`. Override only what differs; don't copy the base.

## Map other path layouts in normalizeEndpoint()
When a provider is served under another path layout (Azure OpenAI's `/openai/deployments/{d}/…`), rewrite the path in `normalizeEndpoint()` so the normal handlers match. Never add the alternate paths to every handler. Custom domains are a host lookup through `ApiKeyResolver`, never an `openai` URL lookup chain: the OpenAI watcher uses that chain as the SDK's base URI.

## Remember async jobs between submit and poll
Async generation (video, Replicate and fal predictions, background responses) is billed when the poll finishes. `GuzzleMiddleware::rememberJob()` caches the model and the submit body per job id, and the poll merges it back, so durations and options reach the metrics. Keep large values (`data:` URIs) out of that cache.

## Register a provider in config
Built-in providers are listed in `config('spectra.providers')` and their pricing in `config('spectra.costs.pricing')`. A new provider also needs its slug's logo in `resources/js/components/ProviderLogo.vue`, a row in the supported providers table in `docs/models.md`, and a line in `CHANGELOG.md`.

## Spectra's own provider calls are untracked
When a handler fetches something itself (a generated file, a token count), call `Http::withoutAITracking()`, so Spectra never records or bills its own requests.

## Fixtures are real responses
Fixtures in `tests/responses/{provider}/` are real response bodies (trimmed, with secrets and long content cut), loaded with `loadMockResponse()`. Keep stream fixtures byte-exact: `.gitattributes` keeps their line endings (Gemini SSE uses CRLF).
