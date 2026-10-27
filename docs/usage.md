# Usage

<a name="automatic-tracking"></a>
## Automatic Tracking

Spectra ships with **watchers** that track outgoing AI requests automatically. If you use the [OpenAI PHP SDK](https://github.com/openai-php/client), [Laravel AI](https://github.com/laravel/ai), [Prism PHP](https://github.com/prism-php/prism), [Guzzle](https://github.com/guzzle/guzzle), or [Laravel's HTTP client](https://laravel.com/docs/http-client) to call any supported AI provider, Spectra detects and tracks the request with zero code changes. There is nothing to configure, wrap, or annotate — tracking starts working the moment you install the package. This includes [streamed responses](#streaming).

The **HttpWatcher** adds Spectra as global middleware to Laravel's HTTP client. Any request sent via the `Http` facade to a recognized AI provider host — such as `api.openai.com`, `api.anthropic.com`, `api.groq.com`, or any other registered provider — is tracked automatically. The middleware identifies the provider from the request hostname, checks that the endpoint is trackable (for example, `/v1/chat/completions` or `/v1/embeddings`), and then reads the response to extract usage metrics. It never reads a response body before your code does, unless the body can be rewound.

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

The **OpenAiWatcher** tracks calls made through the [`openai-php/laravel`](https://github.com/openai-php/laravel) package. This covers all SDK methods — chat completions, embeddings, image generation, audio transcription, and more. If you use the OpenAI PHP SDK, every call is tracked automatically:

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

If your project uses [Prism PHP](https://github.com/prism-php/prism) or [Laravel AI](https://github.com/laravel/ai), requests are also tracked automatically, because both use Laravel's HTTP client:

```php
use Prism\Prism\Facades\Prism;

$response = Prism::text()
    ->using('openai', 'gpt-4o')
    ->withPrompt('Summarize this release note.')
    ->asText();

echo $response->text;
```

Spectra records provider errors too. A 4xx or 5xx response is stored as a failed request, with the provider's error message in the `error_message` column. A connection failure is recorded and still thrown to your code. Saving a tracked request never throws into your application: if saving fails, the error goes to your exception handler.

> [!TIP]
> Automatic tracking applies whenever `spectra.watcher.enabled` is `true` (the default). It works for requests made via Laravel's `Http` facade, the OpenAI PHP SDK, and any library that uses either of these under the hood. Direct `curl` calls or other HTTP libraries are not tracked — use the [Guzzle middleware](#guzzle-middleware) or [manual tracking](#manual-tracking) for those.

<a name="guzzle-middleware"></a>
## Guzzle Middleware

If you use Guzzle directly — for example, with a custom HTTP client that bypasses Laravel's `Http` facade — you can add Spectra's `GuzzleMiddleware` to the Guzzle handler stack. It is the same middleware the watchers use, so requests and streamed responses are tracked in the same way.

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

If you don't know the provider at client creation time, pass `'auto'` and Spectra will detect the provider from the request hostname at runtime:

```php
$stack->push(GuzzleMiddleware::create('auto'));
```

You can also resolve a ready-made handler stack from the container: `app('spectra.guzzle.handler.openai')` for one provider, or `app('spectra.guzzle.handler')` to detect the provider from the host.

<a name="tracking-any-endpoint"></a>
## Tracking Any Endpoint

The watchers only track known provider hosts and endpoints. To track a request to another host (for example, a proxy) or another endpoint of a provider, use the `withAITracking()` macro. It also takes per-request options, such as tags, metadata, or a pricing tier:

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

For full control over what gets tracked and how, use `Spectra::track()`. This wraps any callable in a tracking context and lets you attach tags, user attribution, conversation IDs, and custom metadata. The callback receives a `RequestContext` instance that you can use to enrich the tracked record.

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

The `track()` method automatically records success or failure based on whether the callback throws an exception. The provider and model arguments are used for cost lookup and dashboard classification. Tags attached via `$ctx->addTag()` appear in the dashboard for filtering and grouping — see [Tags](/tags) for more details.

<a name="user-attribution"></a>
### User Attribution

Spectra automatically associates tracked requests with the currently authenticated user when `tracking.auto_track_user` is enabled in the configuration (the default). The user is stored via a polymorphic `trackable` relationship, which means you can query usage and costs per user through the dashboard or the API.

You can also assign a different trackable model — for example, to attribute requests to a team, project, or organization instead of the logged-in user. A trackable you set yourself (with `forTrackable()`, `Spectra::forUser()`, or the `trackable_type` / `trackable_id` options) always wins over the signed-in user:

```php
$result = Spectra::track('openai', 'gpt-4o', function ($ctx) use ($team) {
    $ctx->forTrackable($team);

    return OpenAI::chat()->create([
        'model' => 'gpt-4o',
        'messages' => $messages,
    ]);
});
```

The trackable type is stored as the model's morph alias (`$model->getMorphClass()`). Apps without a morph map store the class name, as before.

<a name="conversation-tracking"></a>
### Conversation Tracking

For multi-turn conversations, you can attach a conversation identifier and turn number to correlate related requests in the dashboard. They are stored in the `conversation_id` and `conversation_turn` columns:

```php
$result = Spectra::track('openai', 'gpt-4o', function ($ctx) use ($conversationId, $turn) {
    $ctx->inConversation($conversationId, $turn);

    return OpenAI::chat()->create([
        'model' => 'gpt-4o',
        'messages' => $messageHistory,
    ]);
});
```

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

Streamed responses are **tracked automatically**. When a stream goes through Laravel's HTTP client (and so Prism and Laravel AI), the OpenAI PHP SDK, or a Guzzle stack with Spectra's middleware, Spectra reads the events as your code reads the stream. It does not read the stream itself, so your code gets every byte.

Spectra records the request when the stream ends. This happens when your code reads the body to the end, closes it, or drops the response before the end (for example, the user stops the reply). Spectra understands:

- Server-sent events (OpenAI, Anthropic, Cohere, and others)
- Newline-delimited JSON (Ollama)
- Streamed JSON arrays (Gemini without `alt=sse`)

An error event inside a stream (for example, an Anthropic `error` event) is recorded as a failed request. Spectra also measures the time to the first token of each stream.

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
> When using the OpenAI **Chat Completions API** (`/v1/chat/completions`) with streaming, you must include `'stream_options' => ['include_usage' => true]` in your request. Without this option, OpenAI does not send token usage data in the stream, and Spectra will record zero tokens and zero cost for the request. This is not required for the **Responses API** (`/v1/responses`), which always includes usage in the `response.completed` event.

### Streamed Image Generation (OpenAI Responses API)

OpenAI's Responses API can stream image generation. Spectra tracks these streams too. When [media persistence](/configuration#media-persistence) is enabled, it stores the final image when the stream finishes.

<a name="spectra-stream"></a>
### Other SDKs: `Spectra::stream()`

Use `Spectra::stream()` only for SDKs that do not go through one of the clients above (for example, an SDK with its own HTTP client). The method returns a `StreamingTracker`. Its `track()` method wraps the stream, yields the text as it arrives, and measures the time to the first token. Call `finish()` when the stream is done:

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

If you wrap a stream that Spectra already tracks on its own (for example, a stream from the OpenAI SDK), the request is recorded **once**, not twice.

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
> **How Spectra stores streaming responses.** Unlike non-streaming responses where the full API response body is stored, streaming responses only persist the **accumulated text content** — not the individual SSE chunks. This is by design: a streaming session can produce hundreds or thousands of chunks, and storing every raw chunk as JSON would result in enormous database entries. The `response_id` stored for streaming requests is taken from the **first chunk** that contains an ID (e.g., `chatcmpl-...` for OpenAI or `msg_...` for Anthropic).

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

In the dashboard, the request page shows a **Trace** card when a trace has
more than one call: every call in order, a timeline, and the total cost,
tokens, and time. You can also filter the requests list by trace id.

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

To give every AI call its own trace id (the 1.x behaviour), set
`SPECTRA_TRACE_PER_REQUEST=false`. See [Tracking Context](/configuration#tracking-context).

<a name="global-configuration"></a>
## Global Configuration

Spectra provides methods to set defaults for all later tracked requests. They are useful in middleware, jobs, or anywhere you want the same context across several AI calls without passing options to each request.

Global settings are **reset between queued jobs and Octane requests** (`Spectra` is a scoped service). They do not leak from one job or request into the next one. Set them where the work happens: in the job, or in middleware.

### Global Tags

Attach tags to every tracked request. This is convenient for adding environment-level or deployment-level context:

```php
Spectra::addGlobalTags(['production', 'us-east-1']);
```

### Global Pricing Tier

Set the pricing tier globally so all requests calculate costs using the correct tier. This is particularly useful when processing batch jobs or operating under a specific pricing agreement. A tier set this way wins over the tier in the response or request:

```php
Spectra::withPricingTier('batch');
```

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

### Combining and Clearing Globals

Global settings can be chained together and cleared when no longer needed:

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

If you need to make a request to an AI provider without Spectra tracking it, use the `withoutAITracking()` macro:

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
> Spectra only tracks requests to known trackable endpoints such as `/v1/chat/completions` and `/v1/embeddings`. Non-trackable endpoints like `/v1/models` or health checks are never tracked, so `withoutAITracking()` is only necessary when you want to explicitly skip tracking on an endpoint that would otherwise be observed.
