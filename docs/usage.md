# Usage

<a name="automatic-tracking"></a>
## Automatic Tracking

Spectra tracks outgoing AI requests automatically. If you call a supported AI provider with the [OpenAI PHP SDK](https://github.com/openai-php/client), [Laravel AI](https://github.com/laravel/ai), [Prism PHP](https://github.com/prism-php/prism), [Guzzle](https://github.com/guzzle/guzzle), or [Laravel's HTTP client](https://laravel.com/docs/http-client), Spectra detects and tracks the request without any code changes. There is nothing to configure, wrap, or annotate. Tracking starts as soon as you install the package, and it covers [streamed responses](#streaming) too.

For Laravel's HTTP client, Spectra adds itself as global middleware. Any request sent through the `Http` facade to a known AI provider host (such as `api.openai.com`, `api.anthropic.com`, `api.groq.com`, or any other registered provider) is tracked. The middleware finds the provider from the request hostname, checks that the endpoint is trackable (for example, `/v1/chat/completions` or `/v1/embeddings`), and then reads the usage metrics from the response. It never reads a response body before your code does, unless the body can be rewound.

```php
use Illuminate\Support\Facades\Http;

$response = Http::withToken(config('services.openai.api_key'))
    ->post('https://api.openai.com/v1/chat/completions', [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'user', 'content' => 'Summarize this release note.'],
        ],
    ]);

// Spectra automatically captures: provider, model, tokens, cost, latency, status
```

Calls made through the [`openai-php/laravel`](https://github.com/openai-php/laravel) package are tracked too. This covers every SDK method: chat completions, embeddings, image generation, audio transcription, and more:

```php
use OpenAI\Laravel\Facades\OpenAI;

$response = OpenAI::chat()->create([
    'model' => 'gpt-4o',
    'messages' => [
        ['role' => 'user', 'content' => 'Summarize this release note.'],
    ],
]);

echo $response->choices[0]->message->content;
```

[Prism PHP](https://github.com/prism-php/prism) and [Laravel AI](https://github.com/laravel/ai) both use Laravel's HTTP client, so their requests are tracked automatically as well:

```php
use Prism\Prism\Facades\Prism;

$response = Prism::text()
    ->using('openai', 'gpt-4o')
    ->withPrompt('Summarize this release note.')
    ->asText();

echo $response->text;
```

Spectra records provider errors too. Any response outside 200–299 (a 4xx, a 5xx, or an unfollowed 3xx), or one with no status code, is stored as a failed request, with the provider's error message in the `error_message` column. A connection failure is recorded and still thrown to your code. Saving a tracked request never throws into your application: if saving fails, the error goes to your exception handler.

> [!TIP]
> Automatic tracking is on whenever `spectra.watcher.enabled` is `true` (the default). It works for requests made through Laravel's `Http` facade, the OpenAI PHP SDK, and any library built on either of them. Direct `curl` calls and other HTTP libraries are not tracked. Use the [Guzzle middleware](#guzzle-middleware) or [manual tracking](#manual-tracking) for those.

<a name="guzzle-middleware"></a>
## Guzzle Middleware

If you use Guzzle directly (for example, a custom HTTP client that bypasses Laravel's `Http` facade), add Spectra's `GuzzleMiddleware` to the Guzzle handler stack. It is the same middleware Spectra uses for automatic tracking, so requests and streamed responses are tracked the same way.

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Spectra\Support\Tracking\GuzzleMiddleware;

$stack = HandlerStack::create();
$stack->push(GuzzleMiddleware::create('openai', 'gpt-4o'));

$client = new Client([
    'handler' => $stack,
    'base_uri' => 'https://api.openai.com/v1/',
    'headers' => [
        'Authorization' => 'Bearer ' . config('services.openai.api_key'),
        'Content-Type' => 'application/json',
    ],
]);

$response = $client->post('chat/completions', [
    'json' => [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'user', 'content' => 'Hello from Guzzle!'],
        ],
    ],
]);
```

If you don't know the provider when you create the client, pass `'auto'`. Spectra then detects the provider from the request hostname:

```php
$stack->push(GuzzleMiddleware::create('auto'));
```

You can also resolve a ready-made handler stack from the container: `app('spectra.guzzle.handler.openai')` for one provider, or `app('spectra.guzzle.handler')` to detect the provider from the host.

<a name="tracking-any-endpoint"></a>
## Tracking Any Endpoint

Automatic tracking only covers known provider hosts and endpoints. To track a request to another host (for example, a proxy) or another endpoint of a provider, use the `withAITracking()` macro. It also takes per-request options, such as tags, metadata, or a pricing tier:

```php
$response = Http::withAITracking('openai', 'gpt-4o', [
    'pricing_tier' => 'batch',
    'tags' => ['nightly-job'],
])
    ->withToken(config('services.openai.api_key'))
    ->post('https://my-proxy.example.com/v1/chat/completions', [
        'model' => 'gpt-4o',
        'messages' => [['role' => 'user', 'content' => 'Hello!']],
    ]);
```

A request sent with `withAITracking()` is tracked once. The global middleware leaves it alone.

<a name="manual-tracking"></a>
## Manual Tracking

For full control over what gets tracked, use `Spectra::track()`. It wraps any callable in a tracking context, so you can attach tags, a user, a conversation ID, and custom metadata. The callback receives a `RequestContext` that you use to add to the tracked record.

```php
use Spectra\Facades\Spectra;
use OpenAI\Laravel\Facades\OpenAI;

$result = Spectra::track('openai', 'gpt-4o', function ($ctx) {
    $ctx->addTag('release-summary');
    $ctx->addTag('high-priority');

    return OpenAI::chat()->create([
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'user', 'content' => 'Summarize this document.'],
        ],
    ]);
});

// $result is the return value of the callback
echo $result->choices[0]->message->content;
```

`track()` records a success, or a failure if the callback throws an exception. The provider and model arguments are used to look up the cost and to group the request in the dashboard. Tags added with `$ctx->addTag()` appear in the dashboard for filtering and grouping. See [Tags](/tags) for more.

<a name="user-attribution"></a>
### User Attribution

When `tracking.auto_track_user` is enabled (the default), Spectra links each tracked request to the authenticated user. The user is stored in a polymorphic `trackable` relationship, so you can see usage and costs per user in the dashboard or through the API.

<img class="screenshot" src="/images/dashboard/trackable-detail.png" alt="One user's usage and spend in the dashboard" />

You can also assign a different trackable model, for example to attribute requests to a team, project, or organization instead of the logged-in user. A trackable you set yourself (with `forTrackable()`, `Spectra::forUser()`, or the `trackable_type` / `trackable_id` options) always wins over the signed-in user:

```php
$result = Spectra::track('openai', 'gpt-4o', function ($ctx) use ($team) {
    $ctx->forTrackable($team);

    return OpenAI::chat()->create([
        'model' => 'gpt-4o',
        'messages' => $messages,
    ]);
});
```

The trackable type is stored as the model's morph alias (`$model->getMorphClass()`). Apps without a morph map store the class name.

<a name="conversation-tracking"></a>
### Conversation Tracking

For multi-turn conversations, attach a conversation ID and a turn number to link related requests in the dashboard. They are stored in the `conversation_id` and `conversation_turn` columns:

```php
$result = Spectra::track('openai', 'gpt-4o', function ($ctx) use ($conversationId, $turn) {
    $ctx->inConversation($conversationId, $turn);

    return OpenAI::chat()->create([
        'model' => 'gpt-4o',
        'messages' => $messageHistory,
    ]);
});
```

Give every call of one user message the same turn number, including tool calls and the embeddings you look up for it. The dashboard then shows them as one turn.

**Calls tracked on their own.** Calls through the HTTP client, the OpenAI SDK, Laravel AI or Prism are tracked without `track()`, so there is no context to set. Set the conversation for the rest of the request or job instead, before the calls:

```php
use Spectra\Facades\Spectra;

Spectra::forConversation($chat, turn: $chat->messages()->where('role', 'user')->count());

$reply = $agent->prompt($message); // every call it makes is in the conversation, at this turn
```

`forConversation()` takes an id or a model (its key is used), and an optional turn number. It lasts until the request, job or command ends, or until `Spectra::clearGlobals()`. A call given its own conversation (the `conversation_id` option, or `$ctx->inConversation()`) keeps it.

The **Conversations** screen lists one row per conversation: its turns and failed calls, the models it used, its trackable, its cost and its last activity. Above the list are totals for every conversation you filter for. The filters are the same as on the requests list, and search matches part of a conversation id.

<img class="screenshot" src="/images/dashboard/conversations.png" alt="The conversations list" />

Open a conversation to read it as a transcript. Each turn shows the user's message and the model's reply, the calls it made, what it cost, and what the conversation has cost so far. The message comes from the turn's first text call and the reply from its last, so an agent's tool loop reads as one exchange. A call without a turn number is a turn of its own.

<img class="screenshot" src="/images/dashboard/conversation-detail.png" alt="A conversation transcript" />

The transcript reads the stored request and response, so it needs `store_prompts` and `store_responses` (both on by default). With either off, the turns still show their calls and costs, without the text. See [Storage](/configuration#storage).

<a name="request-metadata"></a>
### Request Metadata

Use metadata for structured request context (tenant IDs, workflow IDs, experiment variants, batch IDs, and more):

```php
$result = Spectra::track('openai', 'gpt-4o', function ($ctx) use ($tenantId) {
    $ctx->addMetadata('tenant_id', $tenantId);
    $ctx->addMetadata('feature', 'chat-assistant');
    $ctx->addMetadata('experiment', 'prompt-v3');

    return OpenAI::chat()->create([
        'model' => 'gpt-4o',
        'messages' => $messageHistory,
    ]);
});
```

You can also pass metadata in the `track()` options:

```php
Spectra::track('openai', 'gpt-4o', fn () => ..., [
    'metadata' => [
        'tenant_id' => $tenantId,
        'workflow' => 'ticket-triage',
    ],
]);
```

For metadata on every request, use `Spectra::withMetadata([...])` and clear it with `Spectra::clearGlobals()`. See [Metadata](/metadata) for a complete guide.

<a name="streaming"></a>
## Streaming

Streamed responses are tracked automatically. When a stream goes through Laravel's HTTP client (and so Prism and Laravel AI), the OpenAI PHP SDK, or a Guzzle stack with Spectra's middleware, Spectra reads the events as your code reads the stream. It does not read the stream itself, so your code gets every byte.

Spectra records the request when the stream ends. This happens when your code reads the body to the end, closes it, or drops the response before the end (for example, the user stops the reply). Spectra understands:

- Server-sent events (OpenAI, Anthropic, Cohere, and others)
- Newline-delimited JSON (Ollama)
- Streamed JSON arrays (Gemini without `alt=sse`)

An error event inside a stream (for example, an Anthropic `error` event) is recorded as a failed request. Spectra also measures the time to the first token of each stream.

<a name="with-the-laravel-http-client"></a>
### With the Laravel HTTP Client

```php
use Illuminate\Support\Facades\Http;

$response = Http::withToken(config('services.anthropic.api_key'))
    ->withHeaders(['anthropic-version' => '2023-06-01'])
    ->withOptions(['stream' => true])
    ->post('https://api.anthropic.com/v1/messages', [
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 1024,
        'stream' => true,
        'messages' => [
            ['role' => 'user', 'content' => 'Tell me a joke.'],
        ],
    ]);

$body = $response->toPsrResponse()->getBody();

while (! $body->eof()) {
    echo $body->read(1024); // Send the events to the user as they arrive
}

// Spectra records the request when the body has been read to the end.
```

<a name="with-the-openai-sdk"></a>
### With the OpenAI SDK

```php
use OpenAI\Laravel\Facades\OpenAI;

$stream = OpenAI::chat()->createStreamed([
    'model' => 'gpt-4o',
    'messages' => [
        ['role' => 'user', 'content' => 'Write a short story about a robot.'],
    ],
    'stream_options' => ['include_usage' => true],
]);

foreach ($stream as $response) {
    echo $response->choices[0]->delta->content;
}
```

> [!IMPORTANT]
> When you stream from the OpenAI Chat Completions API (`/v1/chat/completions`), you must include `'stream_options' => ['include_usage' => true]` in your request. Without it, OpenAI does not send token usage in the stream, and Spectra records zero tokens and zero cost. The Responses API (`/v1/responses`) doesn't need this, because it always includes usage in the `response.completed` event.

<a name="streamed-image-generation"></a>
### Streamed Image Generation (OpenAI Responses API)

OpenAI's Responses API can stream image generation. Spectra tracks these streams too. When [media persistence](/configuration#media-persistence) is enabled, it stores the final image when the stream finishes.

<a name="spectra-stream"></a>
### Other SDKs: `Spectra::stream()`

Use `Spectra::stream()` only for SDKs that don't go through one of the clients above (for example, an SDK with its own HTTP client). It returns a `StreamingTracker`. Its `track()` method wraps the stream, yields the text as it arrives, and measures the time to the first token. Call `finish()` when the stream is done:

```php
use Spectra\Facades\Spectra;

$tracker = Spectra::stream('anthropic', 'claude-sonnet-4-6');

foreach ($tracker->track($sdkStream) as $text) {
    echo $text;
}

$tracker->finish();
```

`track()` accepts:

- **An iterable** of chunks, such as an SDK stream object or a generator. Each chunk can be an array or an object, in the provider's event format.
- **A string** with the raw stream body (server-sent events, newline-delimited JSON, or a JSON array).

The `finish()` method returns the `SpectraRequest` record, with token counts, cost, latency, and time-to-first-token metrics. You can call it more than once. Later calls return the first result.

If you wrap a stream that Spectra already tracks on its own (for example, a stream from the OpenAI SDK), the request is recorded once, not twice.

If your SDK gives you the text and the usage separately, add them yourself before you call `finish()`:

```php
$tracker = Spectra::stream('my-provider', 'my-model');

foreach ($sdkStream as $delta) {
    $tracker->appendContent($delta->text);
}

$tracker->setUsage([
    'prompt_tokens' => $usage->inputTokens,       // the full prompt, including cached tokens
    'completion_tokens' => $usage->outputTokens,  // the full output, including reasoning tokens
    'cached_tokens' => $usage->cachedTokens,
]);

$tracker->finish();
```

> [!NOTE]
> **How Spectra stores streaming responses.** For a normal response, Spectra stores the full API response body. For a streamed response, it stores only the accumulated text, not the individual SSE chunks. This is on purpose: a stream can produce hundreds or thousands of chunks, and storing each raw chunk as JSON would make huge database rows. The `response_id` of a streamed request comes from the first chunk that has an ID (for example, `chatcmpl-...` for OpenAI or `msg_...` for Anthropic).

<a name="traces"></a>
## Traces

One user action often makes several AI calls: an embedding to search your
documents, a chat completion, then a tool call and a second completion.
Spectra links these calls with a **trace id**.

All AI calls made in the same unit of work share one trace id:

- one HTTP request,
- one queued job,
- one Artisan command.

You don't need to do anything for this. The next request or job starts a new
trace.

**Queued jobs join the trace.** A job dispatched during a request keeps the
trace of that request. So the chain "request → job → AI call" is one trace.
Spectra uses Laravel's [Context](https://laravel.com/docs/context) for this.
The trace id is stored as hidden context, so it is not added to your logs.

**Incoming `traceparent` header.** When the HTTP request has a W3C
`traceparent` header (sent by an OpenTelemetry-aware gateway, proxy, or
service), Spectra uses its trace id. Spectra's
[OpenTelemetry](/opentelemetry#trace-correlation) spans then appear inside
that trace, under the caller's span.

**Your own services join the trace.** When one of your services calls
another over HTTP (an internal API, a loopback MCP server), the second one
starts its own trace. Send the trace and the tags along with
`Spectra::propagationHeaders()`, and put the `spectra.trace` middleware on
the receiving routes:

```php
// The caller
Http::withHeaders(Spectra::propagationHeaders())->post($internalUrl, $payload);

// The receiving service
Route::post('/internal/tools', ToolController::class)->middleware('spectra.trace');
```

The header is a W3C `baggage` header with the trace id and the tags the
caller's AI calls would get at that point (see [Tags](/tags#inherited-tags)).
The AI calls of the receiving request, and of the jobs it dispatches, then join
the caller's trace and carry its tags. Put the middleware only on routes your
own services call: whoever can reach the route can name the trace and tags.

**Where each call came from.** Every call stores its origin: the queued job
class running it, the Artisan command you ran (`artisan reports:send`), or
the route pattern of the HTTP request (`POST /chats/{chat}`). A trace that
starts in a request and continues in a job shows both. Spectra stores the
route pattern, not the URL, so ids and query strings are not saved. Turn it
off with `capture_origin` (see [Configuration](/configuration#tracking-context)).
To name a call yourself, set `$ctx->origin` inside `Spectra::track()`.

Each call also stores its start time to the millisecond in `started_at`, so
the calls of a fast agent loop keep their order and spacing on the timeline.
`created_at` is still stored to the second.

In the dashboard, the **Traces** screen lists one row per trace: its origin,
its calls and failed calls, the models it used, its trackable, its duration
and its cost. Above the list are totals for every trace you filter for: how
many traces there are, how many had a failed call, and what a trace costs and
takes on average. The filters are the same as on the requests list. A trace
stays in the list when one of its calls matches, and its totals still count
all of its calls, so filtering by `gpt-4o` shows every trace that used it at
its full cost. Search matches an exact trace id or part of an origin.

<img class="screenshot" src="/images/dashboard/traces.png" alt="The traces list" />

Open a trace to see its calls on one timeline, in the order they started, to
the millisecond. A new lane starts when the origin changes, for example when a
request dispatches a job. The trace page also shows the cost, calls and tokens
per model, the tags, the trackable and the parent span from `traceparent`.

<img class="screenshot" src="/images/dashboard/trace-detail.png" alt="A trace timeline with a span and five calls" />

The request page shows a **Trace** card when a trace has more than one call,
with an **Open trace** link. As everywhere in the dashboard, cost counts
successful calls only. Duration runs from the start of the first call to the
end of the last one.

<a name="spans"></a>
### Spans

A trace is a tree. Each AI call has a span, and calls can sit inside other spans: the request, job or command they ran in, and the blocks of your code you wrap in `Spectra::span()`.

**Your own spans.** Wrap a step in `Spectra::span()` to see it on the timeline, with the AI calls it makes nested inside:

```php
use Spectra\Facades\Spectra;

$answer = Spectra::span('answer-question', function () use ($question) {
    $documents = Spectra::span('retrieve-documents', fn () => $this->search($question));

    return OpenAI::chat()->create([
        'model' => 'gpt-4o',
        'messages' => $this->prompt($question, $documents),
    ]);
}, ['question_length' => strlen($question)]);
```

`span()` returns what the callback returns. The span stores its start and end to the millisecond, its attributes, and whether the callback threw; the exception is re-thrown unchanged. Spans nest, and a job dispatched inside a span runs under it. Dispatch from a block body, not an arrow function: `fn () => dispatch($job)` returns the pending dispatch, which only dispatches after the span has ended. Outside a trace (with `trace_per_request` off), a span starts one for its duration.

**Tools.** Run a tool the model asked for through `Spectra::tool()` to draw it as a `tool` span with its arguments and result, or turn on `tool_spans` to draw the tools of an agent loop from the results it sends back. See [Tools](/tools).

**Root spans.** Each HTTP request, queued job and Artisan command that makes an AI call gets a span of its own, named by its origin, with its calls and spans inside. A job's span sits under the span that dispatched it, so a trace reads request, job, call. A request, job or command that makes no AI call stores nothing, unless a job it queued does: then the job also saves the spans it came from, so the tree has no gap (a request that queues a job that stores a message and queues its analysis reads request, job, job, call). Such a span is marked `spectra.inferred` in its attributes and ends when it queued the job, since nothing recorded its real end. Turn root spans off with `root_spans` (see [Configuration](/configuration#tracking-context)). They need `trace_per_request`, the default: with it off, each call is a trace of its own and no root span is stored.

Spans are stored in the `spectra_spans` table. They are never billed or counted as requests, and `spectra:prune` deletes them with the requests. The trace page draws the tree: each span with its children indented under it. In the screenshot above, the embedding call sits inside the `retrieve-context` span, which sits inside the `POST /api/chat` request.

<a name="setting-the-trace-yourself"></a>
### Setting the Trace Yourself

```php
// Use your own trace id for the rest of this request or job
// (and the jobs it dispatches).
Spectra::withTraceId($traceId);

// Read the current trace id, for example to log it.
$traceId = Spectra::currentTraceId();

// Start a new trace. Useful in a long-running command that handles many
// separate items in a loop.
foreach ($tickets as $ticket) {
    Spectra::newTrace();

    $this->summarize($ticket);
}

// Give one call its own trace id.
Spectra::track('openai', 'gpt-4o', fn () => ..., ['trace_id' => $traceId]);
```

To give every AI call its own trace id, set
`SPECTRA_TRACE_PER_REQUEST=false`. See [Tracking Context](/configuration#tracking-context).

<a name="global-configuration"></a>
## Global Configuration

These methods set defaults for every request tracked after them. Use them in middleware, jobs, or anywhere you want the same context on several AI calls without passing options to each one.

Global settings are reset between queued jobs and Octane requests (`Spectra` is a scoped service). They do not leak from one job or request into the next one. Set them where the work happens: in the job, or in middleware.

<a name="global-tags"></a>
### Global Tags

Attach tags to every tracked request, for example to record the environment or deployment:

```php
Spectra::addGlobalTags(['production', 'us-east-1']);
```

<a name="global-pricing-tier"></a>
### Global Pricing Tier

Set the pricing tier for all requests, so their costs use the right tier. This helps when you process batch jobs or have a specific pricing agreement. A tier set this way wins over the tier in the response or request:

```php
Spectra::withPricingTier('batch');
```

<a name="global-trackable"></a>
### Global Trackable

Associate all requests with a specific user or Eloquent model. This wins over the signed-in user:

```php
Spectra::forUser($user);

// Pass a user ID and Spectra loads the model for you
// (resolves config('auth.providers.users.model'))
Spectra::forUser(1);

// Or attribute to any Eloquent model
Spectra::forTrackable($team);
```

<a name="combining-and-clearing-globals"></a>
### Combining and Clearing Globals

You can chain global settings, and clear them when you no longer need them:

```php
// Set multiple globals
Spectra::addGlobalTags(['production'])
    ->withPricingTier('batch')
    ->forUser($user);

// Clear all globals (for example, at the end of a long-running command)
Spectra::clearGlobals();
```

<a name="disabling-tracking"></a>
## Disabling Tracking

To send a request to an AI provider without Spectra tracking it, use the `withoutAITracking()` macro:

```php
$response = Http::withoutAITracking()
    ->withToken(config('services.openai.api_key'))
    ->post('https://api.openai.com/v1/chat/completions', [
        'model' => 'gpt-4o',
        'messages' => [['role' => 'user', 'content' => 'Hello!']],
    ]);
```

The `withAITracking()` and `withoutAITracking()` macros do not send any extra header to the provider. If you set the `X-Spectra-Manual-Tracking` header yourself, the global middleware still skips that request.

> [!TIP]
> Spectra only tracks known endpoints such as `/v1/chat/completions` and `/v1/embeddings`. Endpoints like `/v1/models` or health checks are never tracked, so you only need `withoutAITracking()` to skip an endpoint that would otherwise be tracked.
