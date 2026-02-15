# Usage

<a name="automatic-tracking"></a>
## Automatic Tracking

Spectra ships with **watchers** that intercept outgoing HTTP requests automatically. If you use the [OpenAI PHP SDK](https://github.com/openai-php/client), [Laravel AI](https://github.com/laravel/ai), [Prism PHP](https://github.com/prism-php/prism), [Guzzle](https://github.com/guzzle/guzzle), or [Laravel's HTTP client](https://laravel.com/docs/http-client) to call any supported AI provider, Spectra detects and tracks the request with zero code changes. There is nothing to configure, wrap, or annotate — tracking starts working the moment you install the package.

The **HttpWatcher** listens to Laravel's HTTP client events. Any request sent via the `Http` facade to a recognized AI provider host — such as `api.openai.com`, `api.anthropic.com`, `api.groq.com`, or any other registered provider — is intercepted and tracked automatically. The watcher identifies the provider from the request hostname, validates that the endpoint is trackable (for example, `/v1/chat/completions` or `/v1/embeddings`), and then processes the response to extract usage metrics.

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

The **OpenAiWatcher** intercepts calls made through the [`openai-php/laravel`](https://github.com/openai-php/laravel) package. This covers all SDK methods — chat completions, embeddings, image generation, audio transcription, and more. If you use the OpenAI PHP SDK, every call is tracked automatically:

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

If your project uses [Prism PHP](https://github.com/prism-php/prism), requests are also tracked automatically since Prism uses Laravel's HTTP client under the hood:

```php
use EchoLabs\Prism\Prism;

$response = Prism::text()
    ->using('openai', 'gpt-4o')
    ->withPrompt('Summarize this release note.')
    ->generate();

echo $response->text;
```

> [!TIP]
> Automatic tracking applies whenever `spectra.watcher.enabled` is `true` (the default). It works for requests made via Laravel's `Http` facade, the OpenAI PHP SDK, and any library that uses either of these under the hood. Direct `curl` calls or other HTTP libraries are not intercepted — use the [Guzzle middleware](#guzzle-middleware) or [manual tracking](#manual-tracking) for those.

<a name="guzzle-middleware"></a>
## Guzzle Middleware

If you use Guzzle directly — for example, with a custom HTTP client that bypasses Laravel's `Http` facade — you can add Spectra's `GuzzleMiddleware` to the Guzzle handler stack for automatic tracking. The middleware wraps the Guzzle handler and intercepts requests and responses in the same way the built-in watchers do.

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Spectra\Http\GuzzleMiddleware;

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

> [!WARNING]
> The Guzzle middleware does not support tracking streaming responses. For streaming, use [`Spectra::stream()`](#streaming) instead.

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

You can also manually assign a different trackable model — for example, to attribute requests to a team, project, or organization instead of the logged-in user:

```php
$result = Spectra::track('openai', 'gpt-4o', function ($ctx) use ($team) {
    $ctx->forTrackable($team);

    return OpenAI::chat()->create([
        'model' => 'gpt-4o',
        'messages' => $messages,
    ]);
});
```

<a name="conversation-tracking"></a>
### Conversation Tracking

For multi-turn conversations, you can attach a conversation identifier and turn number to correlate related requests in the dashboard:

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

For global process-wide metadata, use `Spectra::withMetadata([...])` and clear it with `Spectra::clearGlobals()`. See [Metadata](/metadata) for a complete guide.

<a name="streaming"></a>
## Streaming

Spectra supports tracking streaming (SSE) responses through the `Spectra::stream()` method. The method returns a `StreamingTracker` that wraps the stream, collects chunks, measures time-to-first-token latency, and persists the complete request when the stream finishes. You must call `finish()` after the stream is consumed to finalize tracking.

### With the OpenAI SDK

```php
use Spectra\Facades\Spectra;
use OpenAI\Laravel\Facades\OpenAI;

$tracker = Spectra::stream('openai', 'gpt-4o');

$stream = OpenAI::chat()->createStreamed([
    'model' => 'gpt-4o',
    'messages' => [
        ['role' => 'user', 'content' => 'Write a short story about a robot.'],
    ],
    'stream_options' => ['include_usage' => true],
]);

foreach ($tracker->track($stream) as $text) {
    echo $text; // Stream text to the user in real-time
}

$tracker->finish();
```

> [!IMPORTANT]
> When using the OpenAI **Chat Completions API** (`/v1/chat/completions`) with streaming, you must include `'stream_options' => ['include_usage' => true]` in your request. Without this option, OpenAI does not send token usage data in the stream, and Spectra will record zero tokens and zero cost for the request. This is not required for the **Responses API** (`/v1/responses`), which always includes usage in the `response.completed` event.

### Streamed Image Generation (OpenAI Responses API)

OpenAI's Responses API supports streaming image generation. Spectra tracks partial image chunks and persists the complete image when the stream finishes:

```php
use Spectra\Facades\Spectra;
use OpenAI\Laravel\Facades\OpenAI;

$tracker = Spectra::stream('openai', 'gpt-image-1');

$stream = OpenAI::responses()->createStreamed([
    'model' => 'gpt-image-1',
    'input' => 'A futuristic city skyline at sunset',
]);

foreach ($tracker->track($stream) as $chunk) {
    // Image streams yield partial base64 image data
}

$tracker->finish();
```

### With the Laravel HTTP Client

```php
$tracker = Spectra::stream('anthropic', 'claude-sonnet-4-20250514');

$response = Http::withToken(config('services.anthropic.api_key'))
    ->withHeaders(['anthropic-version' => '2023-06-01'])
    ->withResponseType('stream')
    ->post('https://api.anthropic.com/v1/messages', [
        'model' => 'claude-sonnet-4-20250514',
        'max_tokens' => 1024,
        'stream' => true,
        'messages' => [
            ['role' => 'user', 'content' => 'Tell me a joke.'],
        ],
    ]);

foreach ($tracker->track($response->body()) as $text) {
    echo $text;
}

$tracker->finish();
```

The `finish()` method returns the persisted `SpectraRequest` record, which includes full token counts, cost, latency, and time-to-first-token metrics.

> [!NOTE]
> **How Spectra stores streaming responses.** Unlike non-streaming responses where the full API response body is stored, streaming responses only persist the **accumulated text content** — not the individual SSE chunks. This is by design: a streaming session can produce hundreds or thousands of chunks, and storing every raw chunk as JSON would result in enormous database entries. The `response_id` stored for streaming requests is taken from the **first chunk** that contains an ID (e.g., `chatcmpl-...` for OpenAI or `msg_...` for Anthropic).

<a name="global-configuration"></a>
## Global Configuration

Spectra provides methods to set defaults that apply to all subsequent requests in the current process. These are useful in middleware, service providers, or anywhere you want consistent context across multiple AI calls without passing options to each individual request.

### Global Tags

Attach tags to every tracked request in the current process. This is convenient for adding environment-level or deployment-level context:

```php
Spectra::addGlobalTags(['production', 'us-east-1']);
```

### Global Pricing Tier

Set the pricing tier globally so all requests calculate costs using the correct tier. This is particularly useful when processing batch jobs or operating under a specific pricing agreement:

```php
Spectra::withPricingTier('batch');
```

### Global Trackable

Associate all requests in the current process with a specific user or Eloquent model:

```php
Spectra::forUser($user);

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

// Clear all globals (e.g., at the end of a middleware)
Spectra::clearGlobals();
```

<a name="disabling-tracking"></a>
## Disabling Tracking

If you need to make a request to an AI provider without Spectra tracking it, use the `withoutAITracking()` macro. This prevents the request from being intercepted by any of Spectra's watchers:

```php
$response = Http::withoutAITracking()
    ->withToken(config('services.openai.api_key'))
    ->post('https://api.openai.com/v1/chat/completions', [
        'model' => 'gpt-4o',
        'messages' => [['role' => 'user', 'content' => 'Hello!']],
    ]);
```

> [!TIP]
> Spectra only tracks requests to known trackable endpoints such as `/v1/chat/completions` and `/v1/embeddings`. Non-trackable endpoints like `/v1/models` or health checks are never tracked, so `withoutAITracking()` is only necessary when you want to explicitly skip tracking on an endpoint that would otherwise be observed.
