# Custom Providers

Spectra ships with built-in support for several AI providers. You can extend it to support any additional provider by creating a provider class and one or more handler classes, then registering the provider in the configuration. Once registered, Spectra automatically detects and tracks requests to the new provider using the same pipeline as built-in providers.

<a name="how-provider-detection-works"></a>
## How Provider Detection Works

When an outgoing HTTP request is intercepted, Spectra's `ProviderRegistry` attempts to match it against all configured providers in two steps:

1. **Host matching** — The request URL's hostname is compared against each provider's `getHosts()` list. Host patterns support `{placeholder}` syntax for variable segments, such as `{resource}.openai.azure.com`.
2. **Endpoint matching** — The URL path is passed through the provider's `normalizeEndpoint()` method, then checked against each handler's `endpoints()` list to confirm the request targets a trackable API endpoint.

Once matched, the provider's handler extracts normalized metrics from the response.

> [!TIP]
> Spectra only observes usage-related endpoints — requests that consume tokens, generate media, or produce other billable output. Non-usage endpoints such as listing models (`/v1/models`), health checks, or configuration lookups are not tracked, even if they target a recognized provider host.

<a name="architecture-overview"></a>
## Architecture Overview

Each provider follows a two-level architecture:

- **Provider class** — Extends `Provider` and defines the provider's hosts, name, and handler list. The provider is a coordinator that delegates all metric extraction to its handlers.
- **Handler classes** — Each handler implements the `Handler` interface and owns the extraction logic for a specific endpoint type (chat completions, embeddings, images, etc.).

```
MyProvider (extends Provider)
├── ChatHandler     → /v1/chat/completions
├── EmbeddingHandler → /v1/embeddings
└── ImageHandler    → /v1/images/generations
```

For providers with a single endpoint type, you need only one handler. For providers with multiple API shapes, create a handler for each endpoint type.

<a name="creating-a-provider"></a>
## Creating a Provider

The following example shows how to create a provider, using Mistral AI as an illustration. Mistral is already a built-in provider, but we use it here because its API shape is straightforward and easy to follow.

<a name="step-1-create-the-handler"></a>
### Step 1: Create the Handler

Handlers implement the `Handler` interface and contain all extraction logic for a specific endpoint type. The handler must define the model type, the endpoints it matches, and methods to extract metrics, the model name, and the response content.

The `MatchesEndpoints` trait provides the `matchesEndpoint()` method required by the `Handler` interface. It performs an exact match against the handler's `endpoints()` array, which is sufficient for most providers. If your endpoints contain dynamic segments (for example, `/v1/models/{model}:generateContent`), you can override `matchesEndpoint()` with a custom regex implementation instead.

```php
<?php

namespace App\Spectra\Handlers;

use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;

class MistralChatHandler implements Handler, ExtractsModelFromResponse
{
    use MatchesEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Text;
    }

    public function endpoints(): array
    {
        return ['/v1/chat/completions'];
    }

    /**
     * Extract token usage metrics from the provider's response.
     * The returned Metrics DTO determines how cost is calculated.
     * promptTokens is the full prompt, completionTokens the full output.
     */
    public function extractMetrics(
        array $requestData,
        array $responseData
    ): Metrics {
        $usage = $responseData['usage'] ?? [];

        return new Metrics(
            tokens: new TokenMetrics(
                promptTokens: $usage['prompt_tokens'] ?? 0,
                completionTokens: $usage['completion_tokens'] ?? 0,
            ),
        );
    }

    /**
     * Extract the model identifier from the response.
     * This is used for pricing lookup and dashboard display.
     */
    public function extractModelFromResponse(array $response): ?string
    {
        return $response['model'] ?? null;
    }

    /**
     * Extract the generated text content from the response.
     * This is stored in the database for inspection in the dashboard.
     */
    public function extractResponse(array $response): ?string
    {
        return $response['choices'][0]['message']['content'] ?? null;
    }
}
```

<a name="step-2-create-the-provider-class"></a>
### Step 2: Create the Provider Class

The provider class ties everything together — it defines the provider's name, recognized hosts, and the list of handlers to use:

```php
<?php

namespace App\Spectra;

use App\Spectra\Handlers\MistralChatHandler;
use Spectra\Providers\Provider;

class MistralProvider extends Provider
{
    public function getProvider(): string
    {
        return 'mistral';
    }

    public function getHosts(): array
    {
        return ['api.mistral.ai'];
    }

    public function handlers(): array
    {
        return [
            app(MistralChatHandler::class),
        ];
    }
}
```

The `Provider` base class requires three abstract methods:

| Method | Purpose |
| --- | --- |
| `getProvider()` | Returns the provider's identifier (e.g., `'mistral'`). Used as the stored `provider` value in the database. |
| `getHosts()` | Returns hostnames to match against. Supports `{placeholder}` patterns for variable segments. |
| `handlers()` | Returns an array of `Handler` instances. The provider routes each request to the matching handler. |

Override `normalizeEndpoint()` when the same API is served under a different path layout. The returned path is used for endpoint matching, handler resolution, and the stored `endpoint` value. The built-in OpenAI provider uses it to map Azure OpenAI paths such as `/openai/deployments/{deployment}/chat/completions` to `/v1/chat/completions`:

```php
public function normalizeEndpoint(string $path): string
{
    return preg_replace('#^/api/#', '/v1/', $path);
}
```

<a name="step-3-register-the-provider"></a>
### Step 3: Register the Provider

Add an entry to the `providers` array in `config/spectra.php`:

```php
'providers' => [
    // Keep existing built-in providers.
    // ...

    // Your custom provider
    'mistral' => ['class' => App\Spectra\MistralProvider::class, 'name' => 'Mistral'],
],
```

Once registered, Spectra will automatically detect and track any HTTP request to `api.mistral.ai` that targets the `/v1/chat/completions` endpoint.

<a name="multi-endpoint-providers"></a>
## Multi-Endpoint Providers

When a provider has multiple API shapes — for example, separate endpoints for text, images, and audio — create a handler for each endpoint type and return them all from the provider's `handlers()` method. The `Provider` base class routes each request to the correct handler based on endpoint matching.

```php
class MyProvider extends Provider
{
    public function handlers(): array
    {
        return [
            app(ImageHandler::class),     // /v1/images/generations
            app(EmbeddingHandler::class), // /v1/embeddings
            app(ChatHandler::class),      // /v1/chat/completions (catch-all)
        ];
    }
}
```

> [!NOTE]
> When each handler targets distinct endpoints, order does not matter — Spectra checks all handlers and selects the one whose endpoint matches. When multiple handlers share the same endpoint (for example, OpenAI's `/v1/responses` serves both text and image generation), implement the `MatchesResponseShape` interface on the specialized handlers so Spectra can disambiguate based on the response body.

<a name="optional-handler-interfaces"></a>
## Optional Handler Interfaces

Handlers can implement additional interfaces for richer metric extraction and behavior:

### `HasFinishReason`

AI providers include a reason for why text generation stopped in each response. OpenAI uses `finish_reason` (`stop`, `length`, `tool_calls`), while Anthropic uses `stop_reason` (`end_turn`, `max_tokens`, `stop_sequence`). Implement `HasFinishReason` to normalize this value so it appears in the dashboard and is available for filtering:

```php
use Spectra\Contracts\HasFinishReason;

class MyChatHandler implements Handler, HasFinishReason
{
    public function extractFinishReason(array $response): ?string
    {
        // OpenAI-compatible format
        return $response['choices'][0]['finish_reason'] ?? null;

        // Anthropic format would be:
        // return $response['stop_reason'] ?? null;
    }
}
```

### `MatchesResponseShape`

Some providers use the same endpoint for different types of output. For example, OpenAI's `/v1/responses` endpoint can return both text completions and generated images. When multiple handlers match the same endpoint, Spectra calls `matchesResponse()` on each handler to determine which one should process the response. The handler that recognizes the response structure wins:

```php
use Spectra\Contracts\MatchesResponseShape;

// This handler claims /v1/responses only when the output contains images
class MyImageHandler implements Handler, MatchesResponseShape
{
    public function endpoints(): array
    {
        return ['/v1/responses'];
    }

    public function matchesResponse(array $data): bool
    {
        // Check if the response contains image generation output
        foreach ($data['output'] ?? [] as $item) {
            if (($item['type'] ?? null) === 'image_generation_call') {
                return true;
            }
        }

        return false;
    }
}

// This handler claims /v1/responses for regular text completions
class MyTextHandler implements Handler, MatchesResponseShape
{
    public function endpoints(): array
    {
        return ['/v1/chat/completions', '/v1/responses'];
    }

    public function matchesResponse(array $data): bool
    {
        return ($data['object'] ?? null) === 'response'
            || ($data['object'] ?? null) === 'chat.completion';
    }
}
```

### `ExtractsModelFromResponse`

Most AI providers include the model name in the JSON response body. Implement `ExtractsModelFromResponse` so Spectra can read the model identifier for pricing lookup and dashboard display. This is the most common model extraction approach — implement it on any handler whose response body contains a model field:

```php
use Spectra\Contracts\ExtractsModelFromResponse;

class MyChatHandler implements Handler, ExtractsModelFromResponse
{
    public function extractModelFromResponse(array $response): ?string
    {
        return $response['model'] ?? null;
    }
}
```

> [!NOTE]
> `extractModelFromResponse()` is not part of the base `Handler` interface — it is opt-in via `ExtractsModelFromResponse`. If your handler does not implement this interface, Spectra falls back to `ExtractsModelFromRequest` (below) or the `model` field in the request body.

### `ExtractsModelFromRequest`

Some providers embed the model name in the URL path rather than in the response body (for example, Google's `/v1/models/{model}:generateContent` or fal.ai's `/fal-ai/flux/dev`). Implement `ExtractsModelFromRequest` to extract the model from the request data or endpoint path. Handlers that implement this interface typically do not implement `ExtractsModelFromResponse`, although both can be combined when the model appears in both the request URL and the response body:

```php
use Spectra\Contracts\ExtractsModelFromRequest;

class MyHandler implements Handler, ExtractsModelFromRequest
{
    public function extractModelFromRequest(
        array $requestData,
        string $endpoint
    ): ?string {
        // Extract from URL path: /v1/models/gemini-pro:generateContent
        if (preg_match('#/models/([^/:]+)#', $endpoint, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
```

### `SkipsResponse`

Some API workflows involve polling for a result that isn't immediately available. Video generation, for example, returns an unfinished job (`queued`, `in_progress`) until processing finishes. Implement `SkipsResponse` and return `true` for these non-terminal states, so Spectra does not record the polls. Only the finished response is tracked:

```php
use Spectra\Contracts\SkipsResponse;

class MyVideoHandler implements Handler, SkipsResponse
{
    public function shouldSkipResponse(array $responseData): bool
    {
        // Only track finished video generations
        return ($responseData['status'] ?? null) !== 'completed';
    }
}
```

If the finished job is fetched more than once, Spectra does not count it twice. Writes with the same provider response id update the existing row, and only the change is added to the daily stats and budgets. Make sure your handler's response id is the job id.

### `HasProviderLatency`

For an async job, the HTTP call that fetched the result only measures a status poll. Implement `HasProviderLatency` on the handler to return the real generation time the provider reports, in milliseconds. Spectra stores it as the request's latency. Return `null` when the response doesn't say:

```php
use Spectra\Contracts\HasProviderLatency;

class MyVideoHandler implements Handler, HasProviderLatency
{
    public function extractProviderLatency(array $responseData): ?int
    {
        // The provider reports the generation time in seconds
        $seconds = $responseData['metrics']['predict_time'] ?? null;

        return is_numeric($seconds) ? (int) round($seconds * 1000) : null;
    }
}
```

### `HasExpiration`

Generated media URLs (images, videos) are often temporary and expire after a set period. Implement `HasExpiration` so Spectra records when the generated content will become unavailable:

```php
use Illuminate\Support\Carbon;
use Spectra\Contracts\HasExpiration;

class MyHandler implements Handler, HasExpiration
{
    public function extractExpiresAt(array $responseData): ?Carbon
    {
        return isset($responseData['expires_at'])
            ? Carbon::createFromTimestamp($responseData['expires_at'])
            : null;
    }
}
```

### `ReturnsBinaryResponse`

Some endpoints return binary data instead of JSON (for example, text-to-speech endpoints return raw audio bytes). Implement this marker interface so Spectra knows not to JSON-decode the response body. Since binary responses cannot contain a model name, these handlers typically implement `ExtractsModelFromRequest` instead of `ExtractsModelFromResponse`:

```php
use Spectra\Contracts\ExtractsModelFromRequest;
use Spectra\Contracts\ReturnsBinaryResponse;

class MySpeechHandler implements Handler, ExtractsModelFromRequest, ReturnsBinaryResponse
{
    public function extractModelFromRequest(
        array $requestData,
        string $endpoint
    ): ?string {
        return $requestData['model'] ?? null;
    }

    public function extractResponse(array $response): ?string
    {
        return '[audio]';
    }
}
```

### `StreamsResponse`

Implement `StreamsResponse` to provide a custom `StreamHandler` for streamed responses. Spectra parses the stream (server-sent events, newline-delimited JSON, or a JSON array) and passes each decoded event to the `StreamHandler`, which extracts text content, token usage, finish reasons, and the model:

```php
use Spectra\Contracts\StreamsResponse;
use Spectra\Support\Tracking\StreamHandler;

class MyChatHandler implements Handler, StreamsResponse
{
    public function streamingHandler(): StreamHandler
    {
        return new MyStreamHandler();
    }
}
```

The `StreamHandler` abstract class requires four methods:

```php
use Spectra\Support\Tracking\StreamHandler;

class MyStreamHandler extends StreamHandler
{
    public function text(array $data): ?string
    {
        return $data['choices'][0]['delta']['content'] ?? null;
    }

    public function usage(array $data, array $currentUsage): array
    {
        $usage = $data['usage'] ?? null;

        if (! is_array($usage)) {
            return $currentUsage; // This event has no usage
        }

        return array_merge($currentUsage, [
            'prompt_tokens' => $usage['prompt_tokens'] ?? 0,         // full prompt
            'completion_tokens' => $usage['completion_tokens'] ?? 0, // full output
            'cached_tokens' => $usage['prompt_tokens_details']['cached_tokens'] ?? 0,
            'reasoning_tokens' => $usage['completion_tokens_details']['reasoning_tokens'] ?? 0,
        ]);
    }

    public function finishReason(array $data): ?string
    {
        return $data['choices'][0]['finish_reason'] ?? null;
    }

    public function model(array $data): ?array
    {
        return isset($data['model']) ? ['model' => $data['model'], 'id' => $data['id'] ?? null] : null;
    }
}
```

`usage()` receives the usage collected so far and returns the updated array. This lets providers that send usage in several events (like Anthropic) add it up. The token keys follow the [`TokenMetrics`](#metrics-dtos) rules: `prompt_tokens` is the full prompt and `completion_tokens` is the full output. The breakdown keys are:

| Key | Part of |
| --- | --- |
| `cached_tokens` | `prompt_tokens` |
| `cache_write_tokens` | `prompt_tokens` |
| `cache_write_1h_tokens` | `cache_write_tokens` |
| `audio_input_tokens` | `prompt_tokens` |
| `image_input_tokens` | `prompt_tokens` |
| `reasoning_tokens` | `completion_tokens` |
| `audio_output_tokens` | `completion_tokens` |
| `image_output_tokens` | `completion_tokens` |

You may also return these extra keys. Spectra applies them to the request:

| Key | Type | Meaning |
| --- | --- | --- |
| `reported_cost_in_cents` | `float` | The cost the provider reported, in US cents. It replaces the catalog price. |
| `tool_calls` | `array<string, int>` | Billable server-tool usage, keyed like the provider's [`toolCallPricing()`](/pricing#tool-call-pricing) |
| `pricing_tier` | `string` | The tier the provider says it served. Ignored when a tier was set explicitly. |
| `cost_multiplier` | `float` | A price multiplier, for example `1.1` for US-only inference |

### `HasMedia`

AI providers often return generated images and videos as temporary URLs that expire after a set period. Implement `HasMedia` to download and persist these files to a Laravel filesystem disk before the URLs expire. `$rawBody` holds the raw response body for binary responses (for example, TTS audio). The following example shows how OpenAI's image handler stores generated images:

```php
use Illuminate\Support\Facades\Http;
use Spectra\Contracts\HasMedia;
use Spectra\Support\MediaPersister;

class MyImageHandler implements Handler, HasMedia
{
    public function storeMedia(
        string $requestId,
        array $responseData,
        ?string $rawBody = null
    ): array {
        $persister = app(MediaPersister::class);
        $stored = [];

        foreach ($responseData['data'] ?? [] as $i => $item) {
            if (isset($item['b64_json'])) {
                // Image returned as base64-encoded data
                $content = base64_decode($item['b64_json']);
                $stored[] = $persister->store($requestId, $i, $content, 'png');
            } elseif (isset($item['url'])) {
                // Image returned as a temporary URL — download before it expires
                $content = Http::withoutAITracking()->get($item['url'])->body();
                $stored[] = $persister->store($requestId, $i, $content, 'png');
            }
        }

        return $stored;
    }
}
```

### `ExtractsPricingTierFromRequest` / `ExtractsPricingTierFromResponse`

Implemented on the **provider class** (not the handler) when the provider offers multiple pricing tiers. These interfaces allow Spectra to detect the active tier from the request or the response. Implement one or both depending on where the tier information is available. Return a Spectra tier name (`standard`, `batch`, `flex`, `priority`, `fast`, `ultrafast`, `hd`) or `null`:

```php
use Spectra\Contracts\ExtractsPricingTierFromRequest;
use Spectra\Contracts\ExtractsPricingTierFromResponse;

class MyProvider extends Provider implements ExtractsPricingTierFromRequest, ExtractsPricingTierFromResponse
{
    public function extractPricingTierFromRequest(array $requestData): ?string
    {
        return $requestData['service_tier'] ?? null;
    }

    public function extractPricingTierFromResponse(array $responseData): ?string
    {
        return $responseData['service_tier'] ?? null;
    }
}
```

The tier from the response wins over the tier from the request, because the provider may serve a request at a different tier than it asked for. A tier set with `Spectra::withPricingTier()` or the `pricing_tier` option wins over both.

The default pricing tier is resolved from config automatically (`spectra.costs.provider_settings.{provider}.default_tier`), so providers do not need to define it themselves. To configure a default tier for your provider, add it to `config/spectra.php`:

```php
'provider_settings' => [
    'myprovider' => [
        'default_tier' => env('SPECTRA_MYPROVIDER_PRICING_TIER', 'standard'),
    ],
],
```

<a name="extracts-cost-multiplier"></a>
### `ExtractsCostMultiplier`

Also implemented on the **provider class**. Use it when the provider charges a surcharge that the response shows, such as Anthropic's 1.1x price for US-only inference (`usage.inference_geo`). Return the multiplier, or `null` for none. Spectra multiplies the model cost by it (tool call fees are not multiplied):

```php
use Spectra\Contracts\ExtractsCostMultiplier;

class MyProvider extends Provider implements ExtractsCostMultiplier
{
    public function extractCostMultiplier(array $responseData, array $requestData): ?float
    {
        return ($responseData['usage']['region'] ?? null) === 'us' ? 1.1 : null;
    }
}
```

<a name="extracts-cost-multiplier-for-model"></a>
### `ExtractsCostMultiplierForModel`

The same, with the model id. Use it when the surcharge depends on the model, such as fal.ai video models, which cost more or less than their catalog price at another resolution or without audio, by a different amount for each model:

```php
use Spectra\Contracts\ExtractsCostMultiplierForModel;

class MyProvider extends Provider implements ExtractsCostMultiplierForModel
{
    public function extractCostMultiplierForModel(string $model, array $responseData, array $requestData): ?float
    {
        return $model === 'my-video-model' && ($requestData['generate_audio'] ?? true) === false ? 0.5 : null;
    }
}
```

For a polled async job, `$requestData` is the body of the request that submitted it.

<a name="metrics-dtos"></a>
## Metrics DTOs

Handlers return a `Metrics` container with typed data transfer objects for each metric category. Use the DTOs that match your handler's model type:

```php
use Spectra\Data\AudioMetrics;
use Spectra\Data\DocumentMetrics;
use Spectra\Data\ImageMetrics;
use Spectra\Data\Metrics;
use Spectra\Data\SearchMetrics;
use Spectra\Data\TokenMetrics;
use Spectra\Data\VideoMetrics;

// Text / LLM response
new Metrics(tokens: new TokenMetrics(
    promptTokens: 1200,      // full prompt: uncached + cache reads + cache writes
    completionTokens: 300,   // full output, including reasoning
    cachedTokens: 800,       // part of promptTokens
    cacheWriteTokens: 200,   // part of promptTokens
    reasoningTokens: 120,    // part of completionTokens
));

// Image generation
new Metrics(image: new ImageMetrics(count: 4, megapixels: 4.2));

// Audio (TTS or STT)
new Metrics(audio: new AudioMetrics(
    durationSeconds: 30.5,
    inputCharacters: 500,    // For TTS
));

// Video generation
new Metrics(video: new VideoMetrics(
    count: 1,
    durationSeconds: 15.0,
));

// Per-search models (for example, rerank)
new Metrics(search: new SearchMetrics(count: 1));

// Per-page document models (for example, OCR)
new Metrics(document: new DocumentMetrics(pages: 12));
```

<a name="token-metrics"></a>
### `TokenMetrics`

`promptTokens` must be the **full** prompt and `completionTokens` the **full** output. Every other field is a part of one of these two totals, so nothing is counted twice. If your provider reports a part outside its totals (for example, Anthropic leaves cache tokens out of `input_tokens`), add it to the total yourself.

| Field | Part of | Description |
| --- | --- | --- |
| `promptTokens` | — | All input tokens: uncached, cache reads, and cache writes |
| `completionTokens` | — | All output tokens, including reasoning |
| `cachedTokens` | `promptTokens` | Input tokens read from the prompt cache |
| `cacheWriteTokens` | `promptTokens` | Input tokens written to the prompt cache |
| `cacheWrite1hTokens` | `cacheWriteTokens` | Cache writes with a 1-hour lifetime. The rest bill at the 5-minute rate. |
| `audioInputTokens` | `promptTokens` | Uncached audio input tokens |
| `imageInputTokens` | `promptTokens` | Uncached image input tokens |
| `reasoningTokens` | `completionTokens` | Output tokens used for reasoning or thinking |
| `audioOutputTokens` | `completionTokens` | Audio output tokens |
| `imageOutputTokens` | `completionTokens` | Image output tokens |

`TokenMetrics::fromArray()` builds the object from a snake_case usage array (`prompt_tokens`, `cached_tokens`, `cache_write_tokens`, ...), and `toUsageArray()` turns it back into one.

<a name="metrics-fields"></a>
### `Metrics`

| Field | Type | Description |
| --- | --- | --- |
| `tokens` | `?TokenMetrics` | Token usage |
| `image` | `?ImageMetrics` | Image count, and output megapixels for models billed per megapixel |
| `audio` | `?AudioMetrics` | Audio duration and input characters |
| `video` | `?VideoMetrics` | Video count and duration |
| `search` | `?SearchMetrics` | Billed search units |
| `document` | `?DocumentMetrics` | Pages, for models billed per page |
| `toolCalls` | `array<string, int>` | Billable server-tool usage, keyed like the provider's [`toolCallPricing()`](/pricing#tool-call-pricing) (for example, `['web_search_requests' => 3]`) |
| `reportedCostInCents` | `?float` | The cost the provider reported for this request, in US cents. When set, it replaces the catalog price. |

```php
$costInDollars = $responseData['usage']['cost'] ?? null;

return new Metrics(
    tokens: new TokenMetrics(promptTokens: 1200, completionTokens: 300),
    toolCalls: ['web_search_requests' => 3],
    reportedCostInCents: $costInDollars !== null ? $costInDollars * 100 : null,
);
```

The handler's `modelType()` decides how the request is classified and shown in the dashboard. The cost formula comes from the model's [pricing unit](/pricing#pricing-units) in the catalog:

| ModelType | Used For | Primary Metric |
| --- | --- | --- |
| `Text` | Chat completions, text generation, OCR | Tokens or pages |
| `Embedding` | Vector embeddings | Tokens (input only) |
| `Image` | Image generation | Image count or megapixels |
| `Video` | Video generation | Video count / duration |
| `Tts` | Text-to-speech | Duration / characters |
| `Stt` | Speech-to-text | Duration |

<a name="openai-compatible-providers"></a>
## OpenAI-Compatible Providers

Many providers offer an OpenAI-compatible `chat/completions` endpoint. For these, extend `OpenAiCompatibleChatHandler`. It already reads the usage, model, finish reason, and response text, and it handles streamed responses too. You only need to list the endpoints:

```php
use Spectra\Providers\Concerns\OpenAiCompatibleChatHandler;

class AcmeChatHandler extends OpenAiCompatibleChatHandler
{
    public function endpoints(): array
    {
        return ['/v1/chat/completions'];
    }
}
```

Override these methods when the provider is different from OpenAI. They are used for normal and streamed responses:

| Method | Override when |
| --- | --- |
| `reasoningTokensAreSeparate(): bool` (protected) | The provider reports reasoning tokens outside `completion_tokens` (like xAI). Return `true`, and Spectra adds them to the output. |
| `reportedCostInCents(array $usage, array $responseData): ?float` | The response contains the charged cost (like OpenRouter `usage.cost`). Return it in US cents. |
| `usageFrom(array $data): ?array` | The usage object is somewhere else in the response or stream event. The default reads `usage`, then `x_groq.usage`. |
| `tokensFromUsage(array $usage): TokenMetrics` | The usage fields have other names. The default reads `prompt_tokens`, `completion_tokens`, `prompt_tokens_details.cached_tokens`, `prompt_tokens_details.cache_write_tokens`, `prompt_tokens_details.audio_tokens`, `completion_tokens_details.reasoning_tokens`, and `completion_tokens_details.audio_tokens`. |

```php
class AcmeChatHandler extends OpenAiCompatibleChatHandler
{
    public function endpoints(): array
    {
        return ['/v1/chat/completions'];
    }

    protected function reasoningTokensAreSeparate(): bool
    {
        return true;
    }

    public function reportedCostInCents(array $usage, array $responseData): ?float
    {
        return isset($usage['cost']) ? (float) $usage['cost'] * 100 : null;
    }
}
```

<a name="adding-pricing"></a>
## Adding Pricing

For accurate cost tracking, create a pricing class for your custom provider. Extend `ProviderPricing` and define models in the `define()` method:

```php
<?php

namespace App\Pricing;

use Spectra\Pricing\ProviderPricing;

class MistralPricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'mistral';
    }

    protected function define(): void
    {
        $this->model('mistral-large-latest', fn ($m) => $m
            ->displayName('Mistral Large')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 600));

        $this->model('mistral-small-latest', fn ($m) => $m
            ->displayName('Mistral Small')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 30));
    }
}
```

Then register it in `config/spectra.php`:

```php
'costs' => [
    'pricing' => [
        'mistral' => \App\Pricing\MistralPricing::class,
        // ...existing providers...
    ],
],
```

> [!TIP]
> All prices in the pricing catalog are stored in **cents**. For token-based models, prices are in cents per million tokens (for example, `200` means $2.00 per million tokens). For unit-based models (images, audio, video), prices are in cents per unit.

<a name="testing-custom-providers"></a>
## Testing Custom Providers

Write fixture-based tests for each handler to verify correct metric extraction. Test coverage should include model name extraction, token or metric extraction, response content extraction, endpoint matching, and edge cases such as missing fields or empty responses:

```php
use App\Spectra\Handlers\MistralChatHandler;

it('extracts metrics from Mistral chat response', function () {
    $handler = new MistralChatHandler();

    $request = ['model' => 'mistral-large-latest', 'messages' => [...]];
    $response = [
        'model' => 'mistral-large-latest',
        'choices' => [
            ['message' => ['content' => 'Hello!'], 'finish_reason' => 'stop'],
        ],
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ],
    ];

    $metrics = $handler->extractMetrics($request, $response);

    expect($metrics->tokens->promptTokens)->toBe(10);
    expect($metrics->tokens->completionTokens)->toBe(5);
    expect($handler->extractModelFromResponse($response))->toBe('mistral-large-latest');
    expect($handler->extractResponse($response))->toBe('Hello!');
});
```
