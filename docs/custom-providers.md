# Custom Providers

Spectra ships with built-in support for several AI providers. You can extend it to support any additional provider by creating a provider class and one or more handler classes, then registering the provider in the configuration. Once registered, Spectra automatically detects and tracks requests to the new provider using the same pipeline as built-in providers.

<a name="how-provider-detection-works"></a>
## How Provider Detection Works

When an outgoing HTTP request is intercepted, Spectra's `ProviderRegistry` attempts to match it against all configured providers through a three-step process:

1. **Host matching** — The request URL's hostname is compared against each provider's `getHosts()` list. Host patterns support `{placeholder}` syntax for variable segments, such as `{resource}.openai.azure.com`.
2. **Endpoint matching** — The URL path is checked against each handler's `endpoints()` list to confirm the request targets a trackable API endpoint.

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

The following example creates support for a Mistral AI provider with a single chat completions endpoint.

<a name="step-1-create-the-handler"></a>
### Step 1: Create the Handler

Handlers implement the `Handler` interface and contain all extraction logic for a specific endpoint type. The handler must define the model type, the endpoints it matches, and methods to extract metrics, the model name, and the response content.

The `MatchesEndpoints` trait provides the `matchesEndpoint()` method required by the `Handler` interface. It performs an exact match against the handler's `endpoints()` array, which is sufficient for most providers. If your endpoints contain dynamic segments (for example, `/v1/models/{model}:generateContent`), you can override `matchesEndpoint()` with a custom regex implementation instead.

```php
<?php

namespace App\Spectra\Handlers;

use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\Handler;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;

class MistralChatHandler implements Handler
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
    public function extractModel(array $response): ?string
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

### `ExtractsModelFromRequest`

Some providers embed the model name in the URL path rather than in the response body (for example, Google's `/v1/models/{model}:generateContent`). When a handler's `extractModel()` returns `null`, the provider falls back to checking if the handler implements `ExtractsModelFromRequest` to extract the model from the request data or endpoint path:

```php
use Spectra\Contracts\ExtractsModelFromRequest;

class MyHandler implements Handler, ExtractsModelFromRequest
{
    public function extractModel(array $response): ?string
    {
        // Response doesn't include the model name
        return null;
    }

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

Some API workflows involve polling for a result that isn't immediately available. Video generation, for example, returns an incomplete response until processing finishes. Implement `SkipsResponse` to prevent Spectra from storing intermediate polling responses — only the final completed response will be tracked:

```php
use Spectra\Contracts\SkipsResponse;

class MyVideoHandler implements Handler, SkipsResponse
{
    public function shouldSkipResponse(array $responseData): bool
    {
        // Only track completed video generations
        return ($responseData['status'] ?? null) !== 'completed';
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

Some endpoints return binary data instead of JSON (for example, text-to-speech endpoints return raw audio bytes). Implement this marker interface so Spectra knows not to JSON-decode the response body. The handler's `extractModel()` will receive an empty array since the response is not parseable:

```php
use Spectra\Contracts\ReturnsBinaryResponse;

class MySpeechHandler implements Handler, ReturnsBinaryResponse
{
    public function extractModel(array $response): ?string
    {
        // Binary response — model must be extracted from request data instead
        return null;
    }

    public function extractResponse(array $response): ?string
    {
        return '[audio]';
    }
}
```

### `StreamsResponse`

Implement `StreamsResponse` to provide a custom `StreamHandler` for streaming (SSE) responses. Spectra uses the `StreamHandler` to extract text content, token usage, and finish reasons from individual streaming chunks:

```php
use Spectra\Contracts\StreamsResponse;
use Spectra\Support\Tracking\StreamHandler;

class MyChatHandler implements Handler, StreamsResponse
{
    public function streamingClass(): StreamHandler
    {
        return new MyStreamHandler();
    }
}
```

The `StreamHandler` abstract class requires three methods:

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
        $usage = $data['usage'] ?? [];

        return [
            'prompt_tokens' => $usage['prompt_tokens'] ?? $currentUsage['prompt_tokens'],
            'completion_tokens' => $usage['completion_tokens'] ?? $currentUsage['completion_tokens'],
            'cached_tokens' => $currentUsage['cached_tokens'],
        ];
    }

    public function finishReason(array $data): ?string
    {
        return $data['choices'][0]['finish_reason'] ?? null;
    }
}
```

### `HasMedia`

AI providers often return generated images and videos as temporary URLs that expire after a set period. Implement `HasMedia` to download and persist these files to a Laravel filesystem disk before the URLs expire. The following example shows how OpenAI's image handler stores generated images:

```php
use Illuminate\Support\Facades\Http;
use Spectra\Contracts\HasMedia;
use Spectra\Support\MediaPersister;

class MyImageHandler implements Handler, HasMedia
{
    public function storeMedia(
        string $requestId,
        array $responseData
    ): array {
        $persister = app(MediaPersister::class);
        $stored = [];

        foreach ($responseData['data'] ?? [] as $i => $item) {
            if (isset($item['b64_json'])) {
                // Image returned as base64-encoded data
                $content = base64_decode($item['b64_json']);
                $stored[] = $persister->store($requestId, $i, $content, 'image', 'png', 'b64_json');
            } elseif (isset($item['url'])) {
                // Image returned as a temporary URL — download before it expires
                $content = Http::withoutAITracking()->get($item['url'])->body();
                $stored[] = $persister->store($requestId, $i, $content, 'image', 'png', $item['url']);
            }
        }

        return $stored;
    }
}
```

### `ExtractsPricingTierFromRequest` / `ExtractsPricingTierFromResponse`

Implemented on the **provider class** (not the handler) when the provider offers multiple pricing tiers. These interfaces allow Spectra to detect the active tier from request or response metadata. Implement one or both depending on where the tier information is available:

```php
use Spectra\Contracts\ExtractsPricingTierFromRequest;
use Spectra\Contracts\ExtractsPricingTierFromResponse;

class MyProvider extends Provider implements ExtractsPricingTierFromRequest, ExtractsPricingTierFromResponse
{
    public function extractPricingTierFromRequest(array $requestData): ?string
    {
        return $requestData['tier'] ?? null;
    }

    public function extractPricingTierFromResponse(array $responseData): ?string
    {
        return $responseData['system_fingerprint'] ?? null;
    }
}
```

The default pricing tier is resolved from config automatically (`spectra.costs.provider_settings.{provider}.default_tier`), so providers do not need to define it themselves. To configure a default tier for your provider, add it to `config/spectra.php`:

```php
'provider_settings' => [
    'myprovider' => [
        'default_tier' => env('SPECTRA_MYPROVIDER_PRICING_TIER', 'standard'),
    ],
],
```

<a name="metrics-dtos"></a>
## Metrics DTOs

Handlers return a `Metrics` container with typed data transfer objects for each metric category. Use the appropriate DTO based on your handler's model type:

```php
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Data\ImageMetrics;
use Spectra\Data\AudioMetrics;
use Spectra\Data\VideoMetrics;

// Text / LLM response
new Metrics(tokens: new TokenMetrics(
    promptTokens: 150,
    completionTokens: 50,
    cachedTokens: 100,       // Optional: prompt-cached tokens
));

// Image generation
new Metrics(image: new ImageMetrics(count: 4));

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
```

The handler's `modelType()` return value determines which pricing formula is applied and how the request is rendered in the dashboard:

| ModelType | Used For | Primary Metric |
| --- | --- | --- |
| `Text` | Chat completions, text generation | Tokens |
| `Embedding` | Vector embeddings | Tokens (input only) |
| `Image` | Image generation | Image count |
| `Video` | Video generation | Video count / duration |
| `Tts` | Text-to-speech | Duration / characters |
| `Stt` | Speech-to-text | Duration |

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
    expect($handler->extractModel($response))->toBe('mistral-large-latest');
    expect($handler->extractResponse($response))->toBe('Hello!');
});
```
