# Changelog

All notable changes to `laravel-spectra` will be documented in this file.

## v4.0.0 - Unreleased

#### Added

- Pricing: the catalog is a JSON file bundled with the package, `resources/pricing/catalog.json`, generated from the providers' pricing pages with `spectra:pricing-scrape` and reviewed before each release. Each provider's section holds its currency, pricing pages, tool call prices, notes and models, and a fal.ai video model lists its per-resolution prices as `variants`. To bill with prices of your own, point `SPECTRA_PRICING_PATH` at a file in the same format: a model in it replaces the bundled model with the same id, and every other model keeps its bundled price. `spectra:pricing-scrape` writes that file, and a provider only your file has is scraped and rewritten whole
- Pricing: the lookup's index is kept in your cache store (`costs.cache`, `SPECTRA_PRICING_CACHE`, on by default) and rebuilt when a pricing file changes, when the package is updated and after `spectra:pricing-scrape` writes a file, so the files aren't parsed on every request. A cache that can't be read or written logs a warning once and the index is built from the files
- Scores: `Spectra::score('helpful', true, traceId: $id)` records user feedback, a model's judgement or a reviewer's label on a call, a trace or a conversation. A yes/no, a number or a category; with a comment, its source (`ScoreSource::User`, `Model`, `Annotation`, `Api`), the user who gave it, and metadata. Without a target it scores the current trace
- Scores: the Overview has a Scores card (share of yes, average, top labels per score name), and the request, trace and conversation pages list their scores. `ScoreRecorded` fires for every saved score, and `spectra:prune` deletes old scores with the requests
- Testing: `Spectra::fake()` keeps scores in memory; `assertScored()`, `assertNotScored()` and `getScores()`
- Alerts: rules in `spectra.alerts.rules` watch the error rate, p95 or average latency, spend or call count over a window, for the whole app or one provider or model. `php artisan spectra:check-alerts` (schedule it) notifies email, a Slack webhook and a JSON webhook when a rule fires, again after `cooldown` minutes, and when it recovers, and dispatches `AlertTriggered` / `AlertResolved`
- Redaction: with `storage.redaction.enabled` (`SPECTRA_REDACTION_ENABLED`), emails, card numbers (Luhn-checked), API keys and bearer tokens, IP addresses and phone numbers are masked as `[REDACTED:email]` and so on in stored request and response bodies, error messages, span attributes and score comments, before anything is saved or queued. Pick the built-in patterns, add your own regexes under `custom`, or a callback with `Spectra::redactUsing()`
- Payload sampling: `storage.sampling.rate` (`SPECTRA_PAYLOAD_SAMPLE_RATE`, default 1) stores request and response bodies for only that share of traces, all calls of a trace together, while every call is still recorded with its tokens, cost and latency. Failed calls keep their bodies (`keep_failures`). The request page says when a body wasn't stored
- Prompt versions: `Spectra::withPrompt('support-answer', 'v3', fn () => ...)` tags every AI call inside the callback with a prompt template and its version (or pass `prompt_name` / `prompt_version` to `track()`, or call `$context->usingPrompt()`). The dashboard's Prompts screen compares versions on calls, error rate, average cost, tokens and latency, and scores, and the requests list filters by prompt
- Tools: `Spectra::tool('get_weather', fn () => ..., $arguments, id: $callId)` runs a tool the model asked for as a `tool` span of the trace, timed exactly, with its arguments and result (stored with `store_prompts`, and sampled and redacted like request bodies). `Spectra::fake()` remembers tool runs; `assertToolCalled()`
- Tool spans: with `tracking.tool_spans` (`SPECTRA_TOOL_SPANS`, off by default), a call that sends tool results back to the model records a span per tool, from the end of the previous call to its start, read from OpenAI Chat Completions and compatible APIs, the OpenAI Responses API, Anthropic and Gemini request bodies, streamed or not. The trace timeline draws tools with their arguments and result, and OpenTelemetry exports them as `execute_tool {name}`
- Error grouping: each failed call is saved with a fingerprint of its provider, status, error type and message, without the ids, numbers, URLs, emails, masked keys and model names that change from call to call. The dashboard's Errors screen lists the groups with their failures, share, daily trend, models and first and last seen; the requests list filters by group, and a failed call links to the calls that failed the same way. `spectra:group-errors` groups failures saved before the new migration
- Annotation queues: queues in `spectra.annotations.queues` name the scores reviewers give (yes/no, a number in a range, or one of a set of options), and can sample a share of the traces whose calls match a filter. Traces are also queued with `Spectra::queueForReview()` or from a trace's page. The dashboard's Review screen shows each queue's progress and walks reviewers through its traces, saving their labels as Annotation scores. Reviewing needs the new `annotateSpectra` gate, which follows `viewSpectra` unless you define it. `spectra:prune` deletes old queue items, and the fake gets `assertQueuedForReview()`
- Evaluations: `Spectra::dataset('refund-questions')->add($input, expected: $output)` builds datasets of examples, also from a call's page or `addRequest()`. A class that extends `Spectra\Evaluations\Evaluation` names a dataset, the code to run on each example and its scorers (closures, or the built-in `ExactMatch` and `Contains`); `php artisan spectra:eval` runs it, each example in a trace of its own tagged `eval`, and saves the run with every output and score. The dashboard's Evals screen lists the runs and datasets, and compares two runs example by example
- Dashboard: the requests, traces, conversations and trackables lists filter by trackable type, so you can look at only the calls attributed to a `User`, a `Team` or any other model you attribute calls to. The filter offers the types actually stored, named by their class or morph alias
- Dashboard: a Users screen lists only users (trackables whose model is authenticatable), next to the Trackables screen that lists them all. The costs screen's Top users card shows only users too, no longer teams, conversations and other trackables, and links to the new screen
- Dashboard: the screens moved to a sidebar on the left that collapses to icons, and the top bar holds the actions: pause and resume recording, refresh, review, maintenance and dark mode
- Conversations: `Spectra::forConversation($chat, turn: 3)` puts every call tracked after it in the conversation and turn, however it is tracked (the HTTP client, the OpenAI SDK, Laravel AI, Prism), until the request or job ends. Before, only calls made with `Spectra::track()` or `Spectra::stream()` could be given a conversation
- Dashboard: the Overview has an Alerts card (each rule's value now and whether it is firing) and a Performance card (each model's error rate and p50/p95/p99 latency, and median time to first token)
- Dashboard: a Maintenance screen (the wrench icon) shows what is stored and runs the upkeep: prune old data with a count of what would be deleted before you confirm, pause and resume recording, and rebuild the daily stats. Prunes and rebuilds run as queued jobs on Spectra's queue. Its Prices card is read-only (the bundled catalog, your own pricing file and the cache): the dashboard runs in production, so prices are checked and written only with `spectra:pricing-scrape`
- Dashboard: a Playground screen lets you write a new prompt for any built-in chat provider, or open a stored call from any request's page, change its messages, model, settings or whole body, send it to the provider again, and compare the reply, cost, tokens and latency with the original. Each run is a real, tracked call tagged `playground`, so it's off by default (`dashboard.playground.enabled`, `SPECTRA_PLAYGROUND_ENABLED`) and needs the `manageSpectra` gate. Works with OpenAI, Anthropic, Google, Groq, xAI, Mistral, OpenRouter, Cohere and Ollama
- Dashboards: read-only pages you design and share with someone outside Spectra, such as a customer who wants to see their own usage. Choose the widgets (the Overview's headline numbers, requests over time, breakdown, top models, recent requests, performance and scores, plus cost over time and budget) and a trackable, or every trackable of a type, on the new Dashboards screen or with `Spectra::dashboard()->create()`, `delete()` and `list()`. A dashboard can also link to pages for the viewer's own requests, traces, conversations, errors and costs (`sections`). Viewers log in through your app and see only their own data; system prompts and raw bodies are never shown, message text only with `show_messages`, and costs only when you turn them on. Off by default (`dashboards.enabled`, `SPECTRA_DASHBOARDS_ENABLED`), and designing one needs the `manageSpectra` gate. The fake gets `assertDashboardCreated()` and `assertDashboardDeleted()`
- `spectra:pricing-scrape` prints the page line each changed price was confirmed on, to check it against without opening the page
- Dashboard: the maintenance actions need the new `manageSpectra` gate (local-only by default) and the `dashboard.maintenance` option (`SPECTRA_DASHBOARD_MAINTENANCE`, on by default)
- `spectra:pause` and `spectra:resume`: while paused, AI calls keep working but no request, span, score or media is stored. Each request or job reads the flag once, and a long-running process every 5 seconds
- Ollama: the legacy `POST /api/embeddings` endpoint is tracked too, as an embedding request at $0 like `/api/embed`. It was skipped altogether
- Costs: a model that is missing from its provider's pricing catalog is reported instead of silently costing $0. Spectra logs a warning once per process for each such model, and the dashboard's Overview says under the spend total how many successful calls in the period were recorded at $0 for want of a price, and names the models. Providers without a catalog (Ollama) stay quiet, as do costs the provider reports itself (OpenRouter)

#### Changed

- Dashboard: built with Tailwind CSS 4, so it needs Safari 16.4, Chrome 111 or Firefox 128 or newer. The layout is unchanged; some status and badge colours (green, amber, red) shift slightly with Tailwind's new palette
- Pricing: the PHP pricing classes (`Spectra\Pricing\OpenAIPricing` and the rest), `ProviderPricing::define()` and `populate()`, the `costs.pricing` config map and the `costs.use_scraped_prices` and `costs.scraped_prices_path` keys are gone; prices come from the bundled catalog and `costs.pricing_path`. `spectra:pricing-scrape` writes to `SPECTRA_PRICING_PATH` (default `resources/spectra/pricing.json`), and written to the bundled catalog itself (`composer pricing-scrape` in the package repository) it rewrites each scraped provider whole, keeping its notes (see the upgrade guide)
- `RequestContext::$id` is the id the call is saved under (a UUID), so code inside `Spectra::track()` can score the call before it is saved. It used to be a separate ULID that was never stored
- `spectra:install` without a terminal (`--no-interaction`, a Composer script) publishes the new migrations and no longer runs them, so it can sit in your app's `post-update-cmd` scripts. (see the `spectra:install` docs)

#### Fixed

- Tracking: a long streamed call that arrives all at once, as it can through a buffering proxy, is saved without a tokens per second. Its rate overflowed the column on PostgreSQL and strict MySQL, so the insert failed and the call was lost
- Dashboard: every price uses one format: two decimals from $1 (`$1,234.56`), four below (`$0.0579`, `$0.0007`), and `<$0.0001` for less, with the exact amount on hover. Tiny costs no longer show in e-notation (`$1.8e-8`), and the cost charts' axes and tooltips use the same format
- Dashboard: hints and cut-off names (a trace's request URL, model names, error messages, ids) show in the dashboard's own tooltip instead of the browser's `title`, which was slow, unstyled and hard to read in dark mode. A name shows its tooltip only when it is cut off
- Dashboard: the trace timeline keeps a sub-cent cost (`$0.0000054`) inside its column, and a call nested in a span shows its full model name
- Dashboard: the page of a Replicate image call opens and shows its images. Its output is a URL or a list of URLs, which made the page answer with a 500 unless the images were stored on the media disk
- Dashboard: a trackable's model tables show each model's provider logo, with one row per provider when the same model ran on two providers. The API left the provider out, so the logo never showed and searching those tables by provider found nothing
- Dashboard: moving between the calls of a trace keeps the video card's expiry and the Expires countdown right for the call shown, and the Payload card opens on a tab that has something to show when a call has no response or no request
- Conversations: a turn's message and reply come from the call the user saw, not from side calls made in the same turn. A guardrail or judge that asks for structured output (JSON schema or JSON mode on OpenAI, Anthropic, Gemini, Ollama and compatible APIs) and a query rewriter are passed over when the turn has a streamed call or one that offers tools, so a turn no longer shows the guardrail's prompt with the whole history and its JSON verdict
- Spans: a queued job whose parent request or job made no AI call no longer hangs off a span that was never saved, which drew it as a second root of the trace and showed the missing parent as an incoming `traceparent`. The job now also saves the spans it came from (see Root spans in the usage docs)
- Tracking: a client's `X-Request-ID` header, a conversation id, a prompt name or version and a tag name longer than their columns are cut to fit (255, 255, 100, 50 and 255 characters), where before the insert failed on MySQL and PostgreSQL and the call was lost, so a client could keep its calls out of the stats and budgets with a long header. A tag that can't be saved no longer keeps the call out of the daily stats, budgets and exports either
- Tracking: `Http::withAITracking()` and `Http::withoutAITracking()` are registered whether Spectra is enabled or not, so `SPECTRA_ENABLED=false` no longer breaks the call sites that use them (they record nothing). Both also work on a pending request now, after `withHeaders()`, `withToken()` and the like, where they threw a "Method not found" error
- Storage: with `store_system_prompts` or `store_tools` off, the `instructions` and `tools` the OpenAI Responses API echoes in its response are removed from the stored response too; before, only the request body was filtered, so the system prompt was still stored with the response
- Dashboard: playing a text-to-speech request's audio inline answered 500 (only the download worked), because the route's optional `{action}` segment had no default in the controller
- Tracking: the error message of a failed call or span is stored without URL query strings and with API keys masked, whether redaction is on or not. A connection error quotes the request URL, and Google's key travels in it as `?key=…`, so the key ended up in `error_message`, on the dashboard and in the OpenTelemetry export
- Config: under a `config/spectra.php` published before 4.0, the new nested keys (`storage.sampling`, `storage.redaction`, `tracking.tool_spans`, `dashboard.maintenance`) are filled in from the package defaults, so `SPECTRA_REDACTION_ENABLED`, `SPECTRA_PAYLOAD_SAMPLE_RATE`, `SPECTRA_TOOL_SPANS` and `SPECTRA_DASHBOARD_MAINTENANCE` work without publishing the config again. The same now holds for the keys 2.0 added (`storage.store_request_data`, `tracking.trace_per_request`, `tracking.use_traceparent`, `tracking.propagate_tags`, `costs.exchange_rates`), which a config published under 1.x never got
- Traces: `spectra_requests.trace_id` is a string column of up to 64 characters, like the spans and scores tables. On PostgreSQL it was still a UUID column, so a call made under `Spectra::withTraceId('order-12345')` wasn't saved, and the scores of a conversation's or a prompt's traces were missing from those screens because the query compared a string column to a UUID one. The new `change_trace_id_to_string_on_spectra_requests_table` migration changes it; a trace id longer than 64 characters is cut
- Budgets: `spectra_budgets.budgetable_id` is a string column, like the trackable ids. On PostgreSQL it was still a UUID column, so `configureAiBudget()->save()` on a model with an integer or ULID key failed with `invalid input syntax for type uuid` and the budget middleware let that model's calls through unchecked. The new `change_budgetable_id_to_string_on_spectra_budgets_table` migration changes it
- Tracking: an async job the provider reports as failed inside an HTTP 200 (a Replicate prediction or an OpenRouter video that ends `failed`, and now an OpenAI background response that ends `failed`, which wasn't recognised before) counts as a failed request everywhere. It keeps its `200` status code and is stored with the provider's error, as before, but `SpectraRequest::failed()` and `isFailed()`, the dashboard's counts and error rates, the Errors screen, the alerts, `Spectra::usage()`, the daily stats and the exported `is_failed` attribute all treated it as a success, so a failed job showed as a successful $0 call and never reached its error group. One rule now decides, as `SpectraRequest::SUCCESSFUL_SQL` / `FAILED_SQL` and the `successful()` / `failed()` scopes: a 2xx status and no recorded error. The status badge of such a call is red (see the upgrade guide)
- Streaming: a stream that breaks off before its end (the connection drops, the provider stops sending) is recorded as a failed request, with the status code the response had, the usage read so far and the exception as its error. It was recorded as a successful `200` call with zero usage, so error-rate alerts never saw it and the tokens the provider billed went missing. A one-pass response body the app couldn't read to the end is recorded as a failure too, not as an empty answer
- Streaming: `Spectra::stream()` records a stream that throws inside the loop when the tracker is released without `finish()`, and the new `fail($exception)` records it at once. Before, the documented loop lost the whole call on a mid-stream exception when it wrapped a stream Spectra also tracked on its own, because the wrapper had claimed the call and the middleware stayed silent for it
- Streaming: a tracked stream the app hasn't started reading is no longer taken over by an unrelated `Spectra::stream()` started in the meantime, which recorded the manual stream under the tracked stream's provider, model and endpoint and left the tracked stream unrecorded. A stream is offered to a wrapper only while its body is being read
- Pricing file: an entry in your pricing file for a bundled model keeps that model's `pricing_unit` and `type` when it leaves them out, and is skipped with a warning when it names a different unit. Before, a hand-written entry without `pricing_unit` turned a per-minute or per-image model (`whisper-1`, `dall-e-3`) into a token model, so every call to it was billed at $0 with no warning
- Google: Imagen has no price because Google shut the Imagen models down and removed them from the pricing page; a `:generateImages` call is still recorded, and is now reported as unpriced instead of looking like a free call
- Dashboard: the Traces and Conversations screens, a trace's and a conversation's page and the request page say the trace migrations are missing until they run, like the Prompts and Errors screens do. They answered 500 (`no such column: started_at`) for every trace, conversation and, with a trace per request, every request page, although calls are recorded without those columns until the migration runs
- Traces: a trace id that isn't a UUID is found again on PostgreSQL once the trace id migration ran (`forTrace()`, the trace page, the requests list's search and the traces search). The guard that kept non-UUID ids away from the old uuid column stayed on after the column became text, so those traces showed as not found
- Budgets: a warning or critical threshold set to `0` is off, as the docs say. It fired at 0% on the first request of every period, so a budget with `warningThresholdPercentage(0)` sent a bogus warning for a user with no spend, and `criticalThresholdPercentage(0)` made the critical alert fire at once and the warning never
- Budgets: reporting the spend of a saved request reads the budget once and then only the spend the budget's cost limits compare against (one query per set limit), instead of an existence check, the budget again and eleven aggregates including two sums over the trackable's whole history. For a user with a daily limit, a saved call costs three queries instead of fourteen
- Tracking: the trackable's key is bound as a string wherever requests, daily stats and budgets are looked up by it, so MySQL uses the index on the string `trackable_id` column instead of comparing every row as a number
- Relations: `aiRequests()`, `aiBudget()` and `activeAiBudget()` on a model with an integer key compare it with the string `trackable_id` and `budgetable_id` columns as a string, when the relation is loaded, eager loaded with `with()`, and in `has()`, `whereHas()` and `withCount()`. On PostgreSQL, `User::with('aiRequests')` and `withCount('aiRequests')` failed with `operator does not exist: character varying = integer`, and MySQL compared every row of the type as a number instead of using the index
- Tracking: a saved request is looked up once, by its response id and row id together, and a response without an id (speech, images) is inserted without a lookup, where every write ran two lookups first. An ordinary call no longer reads the async job cache either: only a poll, which carries no body or names no model, does
- Storage: inline files in a request body (`data:` URIs, such as the base64 image of a vision prompt) are stored as `[stripped]`, like binary data in responses. A vision call with a 1 MB image stored a 1.35 MB row, written in the request path
- Azure OpenAI: an image generation, speech or transcription call to a deployment is recorded under the deployment's name when the response names no model (`dall-e-3`, `tts-1`), so it is priced; it was recorded as `unknown` at $0. Chat and embedding calls keep taking the model from the response
- Daily stats: `spectra_daily_stats` adds cost and latency for successful requests only, like every live query. It added every request's cost and latency, failed ones included, so after a prune the retained history over-reported spend and latency where calls failed; `avg_latency_ms` and `getAggregatedStats()` divide by the successful count. Run `spectra:rebuild-stats` once to correct the stored rows (see the upgrade guide)

#### Upgrading

- Playground: nothing to do. It stays off until you set `SPECTRA_PLAYGROUND_ENABLED=true`
- Pricing: if you extended a pricing class, wrote one for a custom provider or billed with the scraped prices file, move the prices to a pricing file and set `SPECTRA_PRICING_PATH`. A published config that still lists `costs.pricing` logs a warning once per process; with `use_scraped_prices` on, its `scraped_prices_path` is read until you set `SPECTRA_PRICING_PATH`. Set `SPECTRA_PRICING_CACHE=false` to parse the pricing files once per process instead of caching the index
- Scores: run `php artisan spectra:install` to publish and run the new `create_spectra_scores_table` migration. Until you do, `Spectra::score()` returns `null` and logs a warning once per process; tracking is not affected
- Prompt versions: the same command publishes and runs `add_prompt_columns_to_spectra_requests_table`. Until it runs, calls are saved without their prompt and a warning is logged once per process
- Error grouping: the same command publishes and runs `add_error_fingerprint_to_spectra_requests_table`; then run `php artisan spectra:group-errors` once to group the failures saved before it
- Annotation queues: the same command publishes and runs `create_spectra_annotation_items_table`. Until it runs, no trace is queued for review and a warning is logged once per process
- Evaluations: the same command publishes and runs `create_spectra_evaluation_tables`, for datasets and evaluation runs
- Dashboards: the same command publishes and runs `create_spectra_dashboards_table` and `add_sections_to_spectra_dashboards_table`. Until it runs, the Dashboards screen asks you to and `Spectra::dashboard()` fails with a database error. Nothing else changes, because dashboards are off by default
- Budgets: the same command publishes and runs `change_budgetable_id_to_string_on_spectra_budgets_table`. Run it before you rely on budgets on PostgreSQL for models with integer or ULID keys
- Traces: the same command publishes and runs `change_trace_id_to_string_on_spectra_requests_table`. Run it before you call `Spectra::withTraceId()` with ids that aren't UUIDs on PostgreSQL
- `spectra:install` without a terminal no longer runs the migrations it publishes: run `php artisan migrate` after it in deploy scripts
- Dashboard: the maintenance actions need the new `manageSpectra` gate, which allows only the `local` environment unless you define it. Reviewing traces and adding dataset examples need `annotateSpectra`, which follows `viewSpectra` unless you define it

## v3.0.0 - 2026-11-04

#### Added

- Tracking: every call stores where it came from in the new `origin` column: the queued job class, the Artisan command (`artisan reports:send`) or the route pattern of the HTTP request (`POST /chats/{chat}`, never the URL). A synchronous job run inside a request is named by the job. Turn it off with `tracking.capture_origin` (`SPECTRA_CAPTURE_ORIGIN`)
- Tracking: every call stores its start time to the millisecond in the new `started_at` column (`created_at` is stored to the second). OpenTelemetry spans start at it, and never end before it
- Tracking: an async job that is polled again keeps the origin and start time of the call that started it
- Dashboard: a Traces screen lists one row per trace (origin, calls, failures, models, trackable, duration, cost) with totals for the filtered traces. It has the same filters as the requests list; a trace stays when one of its calls matches, with all its calls in its totals
- Dashboard: a trace page shows a trace's calls on one millisecond timeline, with a lane per origin, cost per model, tags and trackable. The request page's Trace card links to it
- Dashboard: a Conversations screen lists one row per `conversation_id` (turns, failures, models, trackable, cost, last activity) with totals for the filtered conversations. A conversation page shows it as a transcript: each turn's user message and reply, its calls, its cost and the cost so far. Calls with the same `conversation_turn` are one turn
- Dashboard: the requests list filters by `conversation_id`, and the request page links its conversation
- Spans: `Spectra::span($name, $callback, $attributes)` runs the callback as a span of the current trace. AI calls, spans and dispatched jobs inside it become its children; it records its start and end to the millisecond, its attributes, and whether it threw
- Spans: each HTTP request, queued job and Artisan command that makes AI calls gets a root span named by its origin, so a trace is a tree (request, job, call). Units of work without an AI call store nothing. Turn it off with `tracking.root_spans` (`SPECTRA_ROOT_SPANS`)
- Spans: every call stores its own `span_id`. OpenTelemetry exports calls with that id and their real parent, and exports the other spans too. A custom exporter gets them by also implementing `Spectra\Contracts\ExportsSpans`
- Dashboard: the trace page draws the trace as a tree of spans and calls
- Testing: `Spectra::fake()` records spans; `assertSpanRecorded()` and `getSpans()`
- `spectra:prune` deletes spans with the requests
- `SPECTRA_FIRECRAWL_API_KEY`, `SPECTRA_FIRECRAWL_API_URL` and `SPECTRA_FIRECRAWL_TIMEOUT`. The unprefixed `FIRECRAWL_*` names still work
- `SpectraRequest::forTrace()` scope
- Dashboard: the costs screen's top spenders include `trackable_type`, so a user and a team with the same id no longer collide
- OpenAI: GPT-6 Rosalind (`gpt-6-rosalind`, with its long-context price) and GPT-6 Astra for Law (`gpt-6-astra-law`, promotional price through 2026-12-31)
- Google: Gemini Robotics-ER 2 Streaming Preview (`gemini-robotics-er-2-streaming-preview`), $1 / $5 per 1M tokens, doubling on 2027-01-01
- Mistral: Voxtral TTS 3 (`voxtral-mini-tts-3-0`, $13 per 1M characters), Voxtral Mini Transcribe 3 ($0.0035 per minute), Voxtral Mini Transcribe Realtime 3 ($0.008) and Voxtral Small Transcribe 3 ($0.004), with the `-3` and `-latest` ids their model cards list. `voxtral-mini-tts-latest` stays on Voxtral TTS (26.03) until Mistral moves it
- fal.ai: Kling 3.0 Pro image-to-video (`fal-ai/kling-video/v3/pro/image-to-video`), priced like text-to-video ($0.168 per second with audio, $0.112 without)

#### Changed

- Dashboard: the navigation collapses into the menu below 1024px wide (was 640px), so the six screens fit
- Config: built-in providers and pricing classes stay active under a published config, so an older `config/spectra.php` no longer misses providers added since (fal.ai, Scaleway) or prices (ElevenLabs billed $0). Set an entry to `false` to turn a built-in off
- Budgets: the `spectra.budget` middleware takes the provider and model from its arguments, then `budget.default_provider` / `budget.default_model`, and no longer reads them from the request input, which let a client name an allowed model and get past `allowed_models` / `allowed_providers`
- Budgets: `BudgetExceeded` (`wasBlocked: true`) now fires before every hard block, not only cost limits. `limitType` is `{period}_tokens` for token limits, `{period}_requests` for request limits, and `provider` or `model` for allow-list blocks (usage and limit both 0)
- A failed request is any status outside 200-299, or none, everywhere: `SpectraRequest::failed()` / `isFailed()` now include 3xx, like the dashboard and `UsageQueryBuilder::failed()` already did, and so does the exported `is_failed`
- Cost and latency count successful (2xx) requests only, and request counts include failures, everywhere: `UsageQueryBuilder` (`stats()`, `byProvider()`, `byModel()`, `dailyUsage()`, `totalCostCents()`, `averageLatency()`), `HasAiUsageStats` and the dashboard's per-model, per-type, top-spender and trackables lists. A model whose requests all failed now shows with $0
- Usage API: a date-only end (`'2026-11-02'`) now includes that whole day in `between()`, the `getAiUsage*` methods and the `SpectraDailyStat` stats queries
- `trackable_id` and tag ids are strings in the dashboard API, so ULID and UUID ids link to the right trackable
- Pricing: dated ids that only add a date to a model id (`claude-sonnet-4-20250514`) are no longer listed as aliases. They resolve to the same price through date stripping, but `getAliases()` no longer returns them
- Tracking: media downloads time out (30 seconds for images, 60 for videos), and a file that fails is skipped
- Dev-only files (`bin/`, `whisky.json`, `pint.json`, `rector.php`, the PHPStan configs) are no longer in the package download
- `SpectraRequest::$trackable_id` is always a string, also on a row just created from an integer key, the same as a row read back from the database
- Config: a whitespace-only API key in config (`spectra.api_keys.*`, `ai.providers.*.key`) counts as unset, like an empty one

#### Fixed

- OpenAI: 12-hour prompt cache writes (GPT-5.6 and later, Responses API) were billed at the 30-minute rate (1.25x input). They now bill at the 12-hour rate (2.5x input), read from the usage's `input_tokens_details.cache_write_tokens_details.ttl_12h_tokens`, for streamed and non-streamed responses
- Usage API: `UsageQueryBuilder::byProvider()`, `byModel()` and `dailyUsage()`, and the `HasAiUsageStats` methods (`getAiUsageForPeriod()`, `getAiUsageByProvider()`, `getAiUsageByModel()` and the today/week/month shortcuts) always reported `total_tokens` as 0: the sum was read through the model's own `total_tokens` accessor
- Tracking errors no longer reach your code. `Spectra::track()` returns your result when recording fails, a malformed stream chunk is skipped instead of ending your `foreach`, and a provider field of the wrong type drops only that field: the request is still recorded with its cost
- Tracking: a retried persist job no longer saves a duplicate row and counts the spend twice, and a failing stats update, budget report, export or `RequestTracked` listener no longer loses the row. A failed save after the response is reported and no longer stops the app's other terminating callbacks
- Budgets: a database or cache error during the middleware's check is reported and the request goes through, instead of a 500
- Tracking: a usage field of the wrong type (an array, a word) counts as 0 tokens instead of whatever a cast made of it (an array counted as 1)
- Budgets: the spend recorded for a tracked request is rounded up to whole cents like every other budget total, so float noise can no longer add a cent
- OpenTelemetry: exports no longer all fail when `SPECTRA_OTEL_TIMEOUT` is set
- Google: Veo videos tracked over HTTP are billed from the submitted duration and resolution (they were recorded at $0)
- Pricing: a model from a second catalog that shares a provider slug is converted at its own catalog's currency, so a custom USD `scaleway` catalog no longer makes the built-in EUR prices count as USD. Tool-call prices too
- Pricing: an unknown `default_tier` (a typo in `SPECTRA_OPENAI_PRICING_TIER`) logs a warning and is stored as `standard`, the tier it is billed at
- Pricing: a scraped prices entry without a `standard` tier is skipped with a warning instead of replacing the built-in model and billing $0
- `spectra:rebuild-stats` and the scheduled rebuild no longer count the last day twice on SQLite
- Dashboard: "Sort by Usage" works on the requests and trackables screens (it was an SQL error on MySQL and PostgreSQL), and a bad sort direction or page size no longer gives a 500
- Dashboard: searching requests by model works on PostgreSQL (a non-UUID search was an SQL error), and a malformed request id or image index is a 404
- Dashboard: the trackables count is right on MariaDB, and trackable names resolve for apps that use a morph map
- Dashboard: Sora video downloads use the configured OpenAI base URL (Azure and custom base URLs got an error)
- Config: a misspelled watcher class in config is reported and skipped instead of breaking the app at boot
- Migrations: both 2.x migrations are safe to run again on a Spectra database that kept its tables after `migrate:fresh`
- Storage: `store_system_prompts` and `store_tools` set to `false` now keep system prompts and tool definitions out of the database. They were saved anyway inside the stored request body. Spectra now removes `system` / `developer` messages, the `system`, `instructions`, `systemInstruction` and `system_prompt` fields, and the `tools` / `functions` lists before saving

#### Upgrading

- This is a major release (3.0). Follow the [upgrade guide](UPGRADE.md) ([online](https://spectraphp.com/upgrade#from-2x-to-3)); every item below is covered there
- Tracking: run `php artisan spectra:install` to publish and run the new `add_trace_timing_columns_to_spectra_tables` migration. Until you do, Spectra keeps recording calls without the two new columns and logs a warning once per process
- Config: a published `config/spectra.php` gets `tracking.capture_origin` with its default (`true`), so you don't need to publish it again
- Spans: run `php artisan spectra:install` to publish and run the new `add_spans_to_spectra_tables` migration too. Until you do, calls are recorded as before, spans are skipped and a warning is logged once per process. A published config gets `tracking.root_spans` (`true`)
- Spans: an async job polled again keeps the `span_id` and `parent_span_id` of the call that started it
- Config: if you removed a built-in provider or pricing class from your published config to turn it off, set it to `false` instead: built-ins now stay active underneath your config
- Budgets: routes that relied on the `spectra.budget` middleware reading `provider` / `model` from the request input must pass them as arguments: `spectra.budget:openai,gpt-4o`
- `BudgetExceeded` listeners that treat `currentUsage` and `limit` as cents should check `limitType` first: token, request and allow-list blocks now fire it too
- Storage: if you turned off `store_system_prompts` or `store_tools`, rows saved before this release still hold system prompts and tools in their `request` column. Prune or clear them if that matters to you
- `RequestContext`'s list properties (`messages`, `tools`, `requestParameters`, `requestData`, `toolCalls`, `toolCallCounts`, `mediaStoragePath`) are `array` and start as `[]` instead of `null`. Code that checks one with `=== null` should check `=== []` (or `blank()`), and code that assigns `null` should assign `[]`. Passing `null` for one to `new RequestContext([...])` still works and means empty. Empty lists are still stored as NULL
- `SpectraRequest::failed()` now includes 3xx responses, and `UsageQueryBuilder` / `HasAiUsageStats` costs and latencies leave out failed requests, so `->failed()->totalCostCents()` is 0. Recheck reports built on the old numbers

## v2.6.0 - 2026-11-01

#### Added

- Scraped prices: set `SPECTRA_USE_SCRAPED_PRICES=true` and the models in `resources/spectra/scraped-prices.json` (`SPECTRA_SCRAPED_PRICES_PATH`) replace the built-in models with the same id; everything else keeps its built-in price. Off by default, so nothing changes until you turn it on. A missing or broken file logs a warning and falls back to the built-in prices
- `spectra:pricing-scrape` reads the providers' pricing pages with Firecrawl's JSON extraction (`FIRECRAWL_API_KEY`) and saves the prices that differ from the built-in catalog, and new models, to the scraped prices file. Every extracted price must appear next to its model in the page text, and rows with mixed-up columns, impossible prices (cached above input, output below input), outliers or wrong units are skipped and reported. It only scrapes the providers you use: the ones you name, else `SPECTRA_SCRAPE_PROVIDERS`, else the providers Spectra has tracked requests for (`--all` scrapes every provider). `--dry-run` prints the changes only
- `ProviderPricing::sources()`: the pricing pages a catalog is scraped from. Every built-in catalog except OpenRouter lists its pages

#### Upgrading

- Nothing to do. With `use_scraped_prices` off (the default), requests are priced as before, and a published `config/spectra.php` gets the new `costs.use_scraped_prices`, `costs.scraped_prices_path` and `costs.firecrawl` keys with their defaults, so you don't need to publish it again
- Pricing: if one of your pricing classes already has a method called `sources()`, it now overrides `ProviderPricing::sources()`: it must be public and return the list of pricing page URLs (or `[]`)

## v2.5.0 - 2026-10-29

#### Added

- Google: Gemini 3.8 Live, Gemini 3.8 Live Extended Thinking, Gemini 3.1 Flash Live Preview, Gemini 3.5 Live Translate Preview and Gemini Omni Flash (`gemini-omni-1.1-flash`); `gemini-3.1-flash-image-preview` resolves to Gemini 3.1 Flash Image
- Mistral: Z.ai GLM 5.3 Flash and DeepSeek V4.1 Flash (`deepseek-v4-1-flash-1017`); `mistral-medium-2604` resolves to Mistral Medium 3.5
- OpenAI: GPT-6.1 Terra
- Scaleway: `deepseek-v4.1-flash-1017`, the dated id the price list now uses, resolves to DeepSeek V4.1 Flash

#### Fixed

- OpenAI: GPT-5.5 and GPT-5.4 cached input above 272K tokens was billed at the short-context rate (it doubles, like input and output). GPT-5.5 Pro had no long-context price at all ($60 / $270 above 272K)
- Google: Gemini Embedding 2 audio ($6.50) and image ($0.45) input was billed at the text rate ($0.20). Gemini 2.5 Computer Use Preview had no long-context price ($2.50 / $15.00 above 200K)
- Mistral: cached input tokens were billed at the full input price. Every current Mistral model now carries its cached price (10% of input). Mistral Large 4 is $0.70 / $2.10 (its launch sale is over)
- OpenRouter: Gemini cache writes were billed at the input price. They now use OpenRouter's `input_cache_write` rate, the context-cache storage price for the 5-minute TTL

## v2.4.0 - 2026-10-29

#### Added

- fal.ai video prices: Kling 3.0 Pro (`fal-ai/kling-video/v3/pro/text-to-video`), Veo 3.1, Veo 3.1 Fast, Veo 3.1 Lite and Wan 2.2 A14B (`fal-ai/wan/v2.2-a14b/text-to-video`), per second of video. The price follows the request's `resolution` and `generate_audio`, and Wan's `num_frames` is counted at 16 frames per second, as fal bills it. Previously fal.ai videos were recorded at $0
- `ExtractsCostMultiplierForModel` contract: a cost multiplier that depends on the model as well as the request
- `Spectra::propagationHeaders()` and the `spectra.trace` middleware continue a trace and its tags across your own services. The caller sends a W3C `baggage` header with the trace id and its current tags, and the AI calls of the receiving route (and its jobs) join that trace with those tags. Previously a loopback call, such as a tool on your own MCP server, started a new, untagged trace
- Tags: queued jobs inherit the tags of the work that dispatched them: global tags and the `withTags()` tags around the dispatch, down a chain of jobs too. Set `spectra.tracking.propagate_tags` (`SPECTRA_PROPAGATE_TAGS`) to `false` to turn this off
- `Spectra::currentTags()` returns the tags a request tracked right now would get

#### Fixed

- Tracking: a polled async job was measured from its result fetch alone, which has no request body, so anything only the submit request states was lost. A queued fal.ai video had no length and cost nothing. Spectra now remembers the submit request's body with the job's model (in the cache, for a day, without inline `data:` files) and uses it for the result. When request data is stored, the result row keeps that body too, so it shows the prompt

## v2.3.1 - 2026-10-25

#### Fixed

- Apps that opt into immutable dates with `Date::use(CarbonImmutable::class)` crashed on every dashboard request: the package type-hinted `Illuminate\Support\Carbon` but fed it values from `now()` and model datetime casts, which are then `Carbon\CarbonImmutable`. Date parameters, properties and return types now accept any `Carbon\CarbonInterface`. This also fixes OpenTelemetry span export, where the `instanceof` check silently turned every timestamp into `0`
- A subclass that overrides one of these methods with a `Carbon` parameter type (for example `UsageQueryBuilder::between()`) must widen it to `CarbonInterface`

## v2.3.0 - 2026-10-24

#### Added

- Azure OpenAI requests are now tracked as `openai`. The `{resource}.openai.azure.com`, `{resource}.cognitiveservices.azure.com` and `{resource}.services.ai.azure.com` hosts are detected, and Azure paths (`/openai/deployments/{deployment}/...`, `/openai/v1/...`, `/openai/responses`) are mapped to the OpenAI `/v1` endpoints. Previously these requests were skipped without any error
- Azure OpenAI: custom Azure domains are detected through `spectra.endpoint_urls.azure` or laravel-ai's `ai.providers.azure.url` (`AZURE_OPENAI_URL`), without replacing the OpenAI base URL
- `Provider::normalizeEndpoint()` lets a provider map another path layout onto its endpoints before matching

## v2.2.0 - 2026-10-21

#### Added

- `Spectra::withTags($tags, $callback)` tags only the requests sent while the callback runs, including the requests Spectra tracks by itself. Calls nest, and the outer tags come back when a call ends, even if it throws. One request or job can now tag each of its AI calls on its own

#### Fixed

- `SpectraFake::startRequest()` ignored global tags, and a request's own metadata replaced the global metadata instead of being merged with it

## v2.1.0 - 2026-10-20

#### Added

- OpenRouter embeddings (`/api/v1/embeddings`) and reranking (`/api/v1/rerank`) are now tracked. Previously these requests matched no handler and were recorded without tokens or cost. Embeddings record input tokens; reranking records search units and, when reported, tokens. Both take their cost from OpenRouter's reported `usage.cost`, including the upstream cost for BYOK, like chat requests

## v2.0.1 - 2026-10-19

#### Fixed

- Migrations failed with "relation already exists" when Spectra used a dedicated `spectra.storage.connection`: the app's `migrate:fresh` only drops tables on the default connection, so the Spectra tables survived while the migrations showed as pending. Both migrations now skip tables and columns that already exist

## v2.0.0 - 2026-10-15

Spectra 2.0 fixes how requests are tracked and priced, updates every pricing
catalog, and ships a redesigned dashboard. See [UPGRADE.md](UPGRADE.md).

#### Tracking fixes

- Streaming through Laravel's HTTP client no longer breaks the app: 1.x read the whole stream before the app could, which left the app with an empty body and stored a zero-token row. Streams are now tracked automatically as the app reads them (HTTP client, Prism, Laravel AI SDK, openai-php, Guzzle stacks), including SSE, newline-delimited JSON and streamed JSON arrays, streams the app stops reading early, and error events sent inside a stream
- Anthropic prompt caching was billed far too low: cache reads were subtracted from `input_tokens` (which already excludes them) and cache writes were never billed. Prompt tokens now include cache reads and writes, and 5-minute and 1-hour writes bill at their own rates
- Gemini thinking tokens and tool-use prompt tokens, and xAI reasoning tokens, are now billed
- The pricing tier the provider reports (batch, flex, priority/fast, ultrafast) now wins over the request's own tier, so downgraded or project-default tiers are priced right
- A finished Replicate prediction threw an exception into the app's own `Http::post()` call (ISO timestamps were subtracted as numbers)
- Polled async jobs (video, background responses, predictions, queued jobs) were counted once per poll in daily stats and budgets; repeat writes now add only what changed
- OpenAI Responses latency was replaced by whole-second provider timestamps
- Trackables were stored with the class name but looked up by morph alias, so budgets and per-user usage found nothing in apps with a morph map
- An explicit trackable was replaced by the signed-in user
- Global tags, trackable, pricing tier and metadata leaked between queued jobs and Octane requests (`Spectra` is now a scoped service)
- Saving a tracked request could throw into the app (e.g. a duplicate daily-stat row under load); failures are now reported instead
- With prompt storage turned off, the raw request body was still saved
- Budget alerts fired on every PHP-FPM request after a threshold, but never again in long-running workers; they now fire once per period, using the cache
- Multipart requests (audio uploads) lost their model when the file came first, which priced them at $0
- Provider error responses are stored with the provider's message; connection failures are recorded and still thrown

#### Pricing

- New pricing dimensions: cache writes, long-context prices (`aboveInputTokens`), prices that change on a date (`since`), audio and image token rates, `fast` and `ultrafast` tiers, `megapixel`, `page` and `request` units, model aliases, retired models, per-catalog currency with exchange rates, provider-reported costs, and regional surcharges
- All 12 catalogs updated to current models and prices (October 2026), including Claude 5.x, GPT-6 / GPT-5.6, Gemini 3.8 / 4, Grok 4.5–4.7, and many corrections; retired models are kept for history and marked
- Model ids resolve through aliases, dated snapshots and routing suffixes, and the model the provider served is priced first

#### Requirements

- Laravel 12 or 13 (Laravel 11 support is dropped; it no longer receives security fixes). PHP 8.2–8.5. The test suite runs on Pest 3, 4 or 5.

#### Added

- One trace per request: all AI calls of the same HTTP request, queued job or Artisan command share a trace id, queued jobs dispatched during a request keep it, and an incoming W3C `traceparent` header is joined (its span becomes the OpenTelemetry parent). New `Spectra::currentTraceId()` and `Spectra::newTrace()`, and `tracking.trace_per_request` / `tracking.use_traceparent` config
- New columns: `cached_tokens`, `cache_write_tokens`, `page_count`, `currency`, `error_type`, `error_message`, `parent_span_id`, `conversation_id`, `conversation_turn`, `request_source`, `laravel_request_id`, `ip_address`, `user_agent` (and matching daily-stat totals)
- `spectra.costs.exchange_rates` config
- `spectra:prune --stats-days`; prune now deletes stored media and works in chunks
- OpenTelemetry spans follow the current GenAI semantic conventions (cache read/write, reasoning, stream, conversation and error attributes)
- Workbench demo data (`vendor/bin/testbench workbench:build`) for working on the dashboard

#### Dashboard

- Redesigned UI: crisper neutral palette, refined typography with tabular numbers, a cleaner header and navigation, sparklines, breakdown legends with amounts and shares, a colour-blind-safe chart palette that follows the light/dark theme, and a new request detail page with the input (uncached, cache read, cache write) and output (answer, reasoning) token breakdown, error details and request origin, and a Trace card that shows every AI call of the same request as a timeline with its total cost, tokens and time

## v1.3.0 - 2026-07-04

#### Added

- Cohere embed and rerank handlers (`/v1` + `/v2` endpoints, billed via `meta.billed_units`) — embed/rerank requests were previously tracked with zero metrics and $0 cost
- Groq Whisper transcription handler (per-minute billing from the `verbose_json` duration)
- Mistral Voxtral audio handlers: transcription (per-minute via `usage.prompt_audio_seconds`) and TTS (per-character, with base64 `audio_data` media persistence)
- `ElevenLabsPricing` catalog (per-character rates from the published API pricing) — ElevenLabs TTS previously recorded $0; Ollama's deliberate $0 is now documented in the pricing docs
- `SearchMetrics` and a `searchCount` on the request context, so per-search models bill the actual number of billed search units (and never bill failed requests)
- Post-persist budget reporting: `BudgetExceeded` (with `wasBlocked = false`) now fires when the request that actually crosses a cost limit is recorded — pre-flight middleware can only see spend from previous requests (documented in the budgets guide)
- `spectra.storage.store_request_data` config key to control storage of raw API request parameters independently of `store_prompts` (defaults to following `store_prompts`)
- `PricingTier::Hd` for DALL·E 3 HD and Sora Pro — explicit `'hd'` tier requests previously fell back to standard pricing silently
- `total_reasoning_tokens` in `SpectraDailyStat::getAggregatedStats()`
- fal.ai and Scaleway provider logos in the dashboard
- Provider tracking tests for OpenRouter, Ollama, and Scaleway; endpoint tests for the media, config, and detail controllers; `EnforceBudgetLimit` middleware tests; characterization tests locking the dashboard endpoint numbers

#### Fixed

- OpenRouter image-generation responses were processed by the chat handler (wrong registration order), losing image counts, media persistence, and per-image cost
- Dashboard cost and latency aggregates were inconsistent: nine queries (overview stats, model-type breakdowns, top models, and the trackable stats/usage/latency endpoints) included failed requests in cost and latency sums while the rest excluded them — cost and latency now aggregate over successful (2xx) requests everywhere, while request counts still include failures
- `Spectra::costs()->monthly()` crashed on SQLite (`EXTRACT()` is not supported) — replaced with a driver-aware period expression covering SQLite, MySQL, and PostgreSQL
- Fractional-cent costs were truncated to integers in `UsageQueryBuilder::stats()`, `totalCostCents()`, and the `HasAiUsageStats` trait (`UsageStats::$totalCostInCents` is now a float)
- 3xx responses were counted in `request_count` but excluded from both `successful_count` and `failed_count`, breaking `success + failed = total`; anything outside 2xx now counts as failed
- Costs-by-user grouped by `trackable_id` alone, merging different polymorphic types that share an id; it now groups by type + id and resolves a `user_name` for the dashboard
- A stream that failed before its first chunk leaked its pending Guzzle context to the next stream, which inherited the wrong provider/model; empty streams no longer persist all-zero request rows
- Budget sums could exceed a limit through float noise alone — sums are rounded to storage precision before the conservative ceil
- Video downloads always sent `Content-Type: video/mp4`; the real stored extension (webm/mov/avi) is now used for the mime type and filename
- `spectra:rebuild-stats` silently did nothing when `--from` was after `--to` (now errors), and gained the missing `#[AsCommand]` attribute
- A typo'd `SPECTRA_DASHBOARD_LAYOUT` silently disabled stat filtering; unknown layouts now log a warning
- OpenAI Responses API image handler checked `prompt_tokens_details` before `input_tokens_details` for cached tokens
- Missing `dist/` assets now log an actionable warning instead of rendering a blank, unstyled dashboard; removed favicon references to files that don't ship

#### Changed

- Removed all 21 `src/Queries/` classes — dashboard aggregates now compose an internal `Support\Query\RequestAggregateQuery` fluent builder that owns the shared scaffolding (date ranges, success filtering, layout/trackable scoping, aggregate select bundles); the cost select alias is standardized to `cost`
- `SpectraRequest` now hides the raw `request`/`response` payloads from serialization by default — the requests list endpoint ships lightweight rows, and the detail endpoint re-exposes them explicitly
- The requests-list search matches model names and exact ids (request id, response id, trace id) instead of running unindexable `LIKE` scans over the full response payload
- Streaming detection is shared via `Support\Tracking\StreamDetector` instead of duplicated across the Guzzle middleware and the Http macro
- `ModelDefinition::pricingUnit()` accepts a `PricingUnit` enum or string and validates it
- `HasAiBudget::getBudgetStatus()` defaults to the configured budget provider/model instead of a hardcoded `gpt-4`
- Migrations are published via `publishesMigrations()`; `per_page` is clamped to 100; `composer analyse` sets a 1G memory limit
- Documentation drift fixed: pricing map (fal.ai, Scaleway, ElevenLabs), budget defaults, new storage key, and budget pre-flight semantics

## v1.2.0 - 2026-06-28

#### Added

- `Spectra::forUser()` now accepts an integer user ID and loads the configured auth user model (`config('auth.providers.users.model')`) automatically, in addition to a `Model` instance
- Date-suffix tolerant pricing lookups — an undated catalog entry (e.g. `claude-opus-4-8`) now matches the dated snapshot id the API returns (e.g. `claude-opus-4-8-20260528`), and OpenAI-style `gpt-4o-2024-08-06` resolves to `gpt-4o`
- `search` pricing unit and `CostCalculator::calculateBySearches()` for per-search billed models (e.g. Cohere Rerank)
- New built-in models: Anthropic (Opus 4.8/4.7, Sonnet 4.6, Fable 5, Mythos 5), OpenAI (GPT-5.5, GPT-5.5 Pro, GPT-5.4 / mini / nano / pro), Google (Gemini 3.5 Flash, 3.1 Pro, 3.1 Flash-Lite, Embedding 2, 3.1 Flash TTS/Image, Veo 3.1 preview), xAI (Grok 4.3, Grok 4.20 variants, Grok Build 0.1), Mistral (Devstral 2, Mixtral 8x7B/8x22B, NeMo, Moderation, Voxtral TTS/Transcribe)
- Facade `@method` annotations for the `SpectraFake` testing helpers (`assertTracked`, `assertRequestCount`, …)
- `PricingCatalogInvariantsTest` and per-unit cost tests guarding against models that silently bill 0
- `declare(strict_types=1)` across the codebase, enforced via a `declare_strict_types` Pint rule

#### Fixed

- Cohere `rerank-v3.5` (and any `search`-unit model) silently billed $0 because the pricing unit was unsupported and fell through to the token path
- Cost was calculated twice — `RequestContext` produced a token-only cost that `RequestPersister` silently overwrote. Both now share a single `RequestCostCalculator`, so the in-memory cost is unit-aware and matches the persisted value
- Corrected stale Mistral prices (Medium 3.5, Small 4, and `mistral-embed` which was priced 10× too low)
- `PruneCommand::handle()` now returns an exit code

#### Changed

- Refreshed the built-in pricing catalog to current provider rates (Anthropic, OpenAI, Google, xAI, Mistral)
- Collapsed duplicated provider handler logic into a shared `OpenAiCompatibleChatHandler` base class and an `ExtractsModelField` trait
- `composer analyse` now analyses `src` only (matching CI) instead of erroring on test-only static-analysis false positives
- Removed the dead `Provider::isStreamingResponse()` method and the unused empty `resources/pricing` directory
- Documented a "Keeping Prices Current" workflow (per-provider source URLs + last-reviewed date) in the pricing docs

## v1.1.0 - 2026-06-05

#### Fixed

- Widen `trackable_id` from UUID to string on `spectra_requests` and `spectra_daily_stats` so trackable models can use UUIDs, ULIDs, or integer primary keys (a new follow-up migration handles the schema change)
- Remove `formatted_expires_at` from `SpectraRequest::$appends` and append it explicitly in `GetRequestDetails`, preventing `MissingAttributeException` on partial-select endpoints when the consuming app enables strict-mode models

## v1.0.0

#### Added

- Add support for [Scaleway](https://www.scaleway.com) provider (Generative APIs: chat, embeddings, and Whisper transcription)

## v1.0.0-beta.1 - 2026-06-03

#### Added

- Add support for Laravel 13

## v0.2.0 - 2026-01-26

#### Breaking Changes

- Extract `extractModelFromResponse()` from `Handler` contract into a new `ExtractsModelFromResponse` interface. Custom handlers that implement this method must now implement `ExtractsModelFromResponse` separately.

#### Added

- Add support for [fal.ai](https://fal.ai) provider (image generation)
- Add `ExtractsModelFromResponse` contract for handlers that extract model names from response payloads

#### Changed

- Make GPT-5.2 the default model in `budget` instead of GPT-4
- Update `custom-providers.md` documentation

## v0.1.0 - 2026-01-16

- Initial release
