# Configuration

All configuration lives in `config/spectra.php`, published during installation. Every option can be controlled via environment variables. This page provides a complete reference for all available settings.

<a name="master-switch"></a>
## Master Switch

```php
'enabled' => env('SPECTRA_ENABLED', true),
```

When set to `false`, Spectra does nothing — no request interception, no tracking, no database writes, and no dashboard routes. This allows you to disable observability entirely in specific environments without removing the package from your application.

<a name="storage"></a>
## Storage

Controls how and what Spectra persists to the database. These settings let you balance storage consumption, privacy requirements, and debugging needs.

```php
'storage' => [
    'connection'           => env('SPECTRA_DB_CONNECTION'),
    'store_requests'       => env('SPECTRA_STORE_REQUESTS', true),
    'store_responses'      => env('SPECTRA_STORE_RESPONSES', true),
    'store_prompts'        => env('SPECTRA_STORE_PROMPTS', true),
    'store_system_prompts' => env('SPECTRA_STORE_SYSTEM_PROMPTS', true),
    'store_tools'          => env('SPECTRA_STORE_TOOLS', true),
    'store_request_data'   => env('SPECTRA_STORE_REQUEST_DATA'),
    'store_embeddings'     => env('SPECTRA_STORE_EMBEDDINGS', false),
],
```

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `connection` | `string\|null` | `null` | Database connection name. `null` uses the application default. Set to a separate connection for high-volume applications to isolate observability writes. |
| `store_requests` | `bool` | `true` | Save request records to the database. When `false`, nothing is saved: requests are still tracked in memory, and still sent to the `RequestTracked` event and to OpenTelemetry. |
| `store_responses` | `bool` | `true` | Persist response payloads (the JSON returned by the provider). |
| `store_prompts` | `bool` | `true` | Store user prompt text and chat messages. Disable for privacy compliance (GDPR, CCPA). |
| `store_system_prompts` | `bool` | `true` | Store system prompts separately. These often contain proprietary business logic. |
| `store_tools` | `bool` | `true` | Store function and tool definitions sent to the AI provider. These can be large for complex tool schemas. |
| `store_request_data` | `bool\|null` | `null` | Store the raw request body sent to the provider (the full JSON body, including sampling settings) in the `request` column. When `null`, follows `store_prompts`. When it resolves to `false`, the raw body is not saved. |
| `store_embeddings` | `bool` | `false` | Store embedding vector values in the response column. Embedding requests are always tracked (tokens, costs, latency), but the float-vector arrays can be very large. When disabled, vectors are replaced with `[stripped]`. |

<a name="media-persistence"></a>
### Media Persistence

Some AI endpoints return media with expiring URLs — for example, DALL-E images expire after approximately one hour, and Sora videos include an `expires_at` timestamp. When media persistence is enabled, Spectra downloads and stores generated media to a Laravel filesystem disk before the URLs expire.

```php
'storage' => [
    'media' => [
        'enabled' => env('SPECTRA_MEDIA_ENABLED', false),
        'disk'    => env('SPECTRA_MEDIA_DISK'),
        'path'    => env('SPECTRA_MEDIA_PATH', 'spectra'),
    ],
],
```

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `media.enabled` | `bool` | `false` | Enable automatic media download and persistence. |
| `media.disk` | `string\|null` | `null` | Laravel filesystem disk to store media on. `null` uses the default filesystem disk. Use `s3` or another cloud disk in production for accessibility across servers. |
| `media.path` | `string` | `spectra` | Directory path within the disk. |

> [!NOTE]
> **Binary data is always stripped from stored responses.** Regardless of whether media persistence is enabled, Spectra replaces inline base64-encoded data with `[stripped]` placeholders before saving responses to the database. This prevents multi-megabyte base64 strings from bloating the `response` column. The following formats are stripped automatically:
> - OpenAI DALL-E: `data[].b64_json`
> - OpenAI Responses API: `output[].result` (image generation calls)
> - Google Gemini: `candidates[].content.parts[].inlineData.data` (images and audio)
> - Google Imagen: `generatedImages[].image.imageBytes`
>
> To preserve the actual media files, enable media persistence above. Spectra will decode and store the files on disk, and the dashboard will serve them from the `media_storage_path` column.

Media files are stored as flat file paths in the `media_storage_path` JSON column (e.g. `["spectra/abc123/0.png", "spectra/abc123/1.png"]`). The dashboard serves images and audio directly from these paths via internal API routes. When [`spectra:prune`](/commands#spectra-prune) deletes a request, it deletes the request's stored media files too.

<a name="api-keys"></a>
## API Keys

Optional explicit API keys per provider. These take highest priority in the resolution chain and are used by internal operations such as media downloads and Google embedding token counting (via the `countTokens` API).

Spectra resolves API keys using a lookup chain that checks multiple config locations per provider. For example, for Google it checks: `spectra.api_keys.google` → `services.google.api_key` → `services.google.key` → `prism.providers.gemini.api_key` → `ai.providers.google.key`. If any key is found in the chain, it is used.

```php
'api_keys' => [
    'openai'    => env('SPECTRA_OPENAI_API_KEY'),
    'anthropic' => env('SPECTRA_ANTHROPIC_API_KEY'),
    // ...
],
```

<a name="queue"></a>
## Queue

Controls whether request persistence happens synchronously, after the HTTP response, or via a background queue job.

```php
'queue' => [
    'enabled'        => env('SPECTRA_QUEUE_ENABLED', false),
    'connection'     => env('SPECTRA_QUEUE_CONNECTION'),
    'queue'          => env('SPECTRA_QUEUE_NAME'),
    'delay'          => env('SPECTRA_QUEUE_DELAY'),
    'after_response' => env('SPECTRA_AFTER_RESPONSE', false),
],
```

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `enabled` | `bool` | `false` | Dispatch a `PersistSpectraRequestJob` instead of writing synchronously. |
| `connection` | `string\|null` | `null` | Queue connection name. `null` uses the application default. |
| `queue` | `string\|null` | `null` | Queue name to dispatch to. |
| `delay` | `int\|null` | `null` | Seconds to delay before processing the job. |
| `after_response` | `bool` | `false` | When queue is disabled, persist after the HTTP response is sent to the client. Has no effect when queue is enabled or in non-HTTP contexts. |

<a name="persistence-modes"></a>
### Persistence Modes

| Mode | Config | Behavior | Best For |
| --- | --- | --- | --- |
| **Sync** (default) | Both `false` | Write to database immediately | Development, low-volume applications |
| **After Response** | `after_response: true` | Write after HTTP response is sent | Production web applications without queues |
| **Queue** | `enabled: true` | Dispatch `PersistSpectraRequestJob` | High-volume production applications |

> [!WARNING]
> After-response mode only works during web requests. Console commands, queue workers, and scheduled tasks always persist synchronously unless queue mode is enabled.

<a name="tracking-context"></a>
## Tracking Context

Configure what contextual information Spectra captures automatically with each tracked request.

```php
'tracking' => [
    'auto_track_user'    => true,
    'capture_ip'         => true,
    'capture_user_agent' => true,
    'capture_request_id' => true,
    'trace_per_request'  => true,
    'use_traceparent'    => true,
    'propagate_tags'     => true,
],
```

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `auto_track_user` | `bool` | `true` | Automatically associate requests with the authenticated user via the polymorphic `trackable` relationship. A trackable you set yourself always wins over the signed-in user. |
| `capture_ip` | `bool` | `true` | Store the client's IP address in the `ip_address` column. Disable for GDPR or CCPA compliance. |
| `capture_user_agent` | `bool` | `true` | Store the client's user agent string in the `user_agent` column. |
| `capture_request_id` | `bool` | `true` | Store a request ID for correlation with your logs in the `laravel_request_id` column. Uses the `X-Request-ID` header if present, otherwise generates one. |
| `trace_per_request` | `bool` | `true` | Give every AI call of the same HTTP request, queued job, or Artisan command one trace id. Queued jobs dispatched during a request keep its trace. Env: `SPECTRA_TRACE_PER_REQUEST`. See [Traces](/usage#traces). |
| `use_traceparent` | `bool` | `true` | When the incoming HTTP request has a W3C `traceparent` header, use its trace id, so Spectra's spans join that trace. Env: `SPECTRA_USE_TRACEPARENT`. |
| `propagate_tags` | `bool` | `true` | A queued job inherits the tags of the request or job that dispatched it (global tags and the `withTags()` tags around the dispatch). Env: `SPECTRA_PROPAGATE_TAGS`. See [Inherited Tags](/tags#inherited-tags). |

These values come from the current HTTP request. In Artisan commands and queue workers there is no real client, so they don't mean much there. Spectra also stores where the call came from in the `request_source` column: `web`, `api` (the request expects JSON), or `console` (Artisan commands and queue workers). Turn the `capture_*` options off if you don't want these values stored.

<a name="dashboard"></a>
## Dashboard

Configure the built-in SPA dashboard.

```php
'dashboard' => [
    'domain'      => env('SPECTRA_DOMAIN'),
    'enabled'     => env('SPECTRA_DASHBOARD_ENABLED', true),
    'path'        => env('SPECTRA_PATH', 'spectra'),
    'middleware'   => ['web'],
    'layout'      => env('SPECTRA_DASHBOARD_LAYOUT', 'full'),
    'date_format' => env('SPECTRA_DATE_FORMAT', 'M j, Y g:i:s A'),
],
```

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `domain` | `string\|null` | `null` | Optional subdomain for the dashboard (e.g., `spectra` serves it at `spectra.yourdomain.com`). |
| `enabled` | `bool` | `true` | Enable or disable the web dashboard. Set to `false` for headless (API tracking only) mode. |
| `path` | `string` | `spectra` | URI path where the dashboard is served. |
| `middleware` | `array` | `['web']` | Middleware applied to dashboard routes. Add `'auth'` to require authentication. |
| `layout` | `string` | `full` | Which model types the dashboard displays. Options: `full`, `text`, `embedding`, `image`, `video`, `audio`. |
| `date_format` | `string` | `M j, Y g:i:s A` | PHP date format string for timestamps in the dashboard. |

<a name="costs"></a>
## Costs

Configure cost calculation and pricing tiers.

```php
'costs' => [
    'enabled'         => true,
    'currency'        => 'USD',
    'exchange_rates'  => [
        // 'EUR' => 1.17,
    ],
    'currency_symbol' => '$',

    'provider_settings' => [
        'openai' => [
            'default_tier' => env('SPECTRA_OPENAI_PRICING_TIER', 'standard'),
        ],
        'anthropic' => [
            'default_tier' => env('SPECTRA_ANTHROPIC_PRICING_TIER', 'standard'),
        ],
    ],

    'pricing' => [
        'openai'     => \Spectra\Pricing\OpenAIPricing::class,
        'anthropic'  => \Spectra\Pricing\AnthropicPricing::class,
        'google'     => \Spectra\Pricing\GooglePricing::class,
        'xai'        => \Spectra\Pricing\XAiPricing::class,
        'mistral'    => \Spectra\Pricing\MistralPricing::class,
        'openrouter' => \Spectra\Pricing\OpenRouterPricing::class,
        'replicate'  => \Spectra\Pricing\ReplicatePricing::class,
        'cohere'     => \Spectra\Pricing\CoherePricing::class,
        'groq'       => \Spectra\Pricing\GroqPricing::class,
        'falai'      => \Spectra\Pricing\FalAiPricing::class,
        'scaleway'   => \Spectra\Pricing\ScalewayPricing::class,
        'elevenlabs' => \Spectra\Pricing\ElevenLabsPricing::class,
    ],
],
```

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `enabled` | `bool` | `true` | Calculate and store costs for each tracked request. |
| `currency` | `string` | `USD` | The currency costs are stored in (cents of this currency). Catalog prices in another currency (Scaleway is in EUR), and costs that providers report in US dollars, are converted with `exchange_rates`. |
| `exchange_rates` | `array` | `[]` | How many units of `currency` one unit of another currency is worth. For example, `['EUR' => 1.17]` when you report in USD, or `['USD' => 0.86]` when you report in EUR. A missing rate counts as 1:1, and Spectra logs a warning once per process. Spectra does not fetch rates, so update them from time to time. |
| `currency_symbol` | `string` | `$` | Symbol shown before cost values in the dashboard. Display only. |
| `provider_settings.*.default_tier` | `string` | `standard` | The tier used when you did not set one (with `Spectra::withPricingTier()` or the `pricing_tier` option), and neither the request nor the response says which tier was used. Tiers: `standard`, `batch`, `flex`, `priority`, `fast`, `ultrafast`, `hd`. Any provider slug may be listed. See [Which Tier Is Used](/costs#tier-order). |
| `pricing` | `array` | See above | Map provider slugs to their `ProviderPricing` class. See [Pricing](/pricing) for customization. |

<a name="budget"></a>
## Budget

Configure budget enforcement defaults.

```php
'budget' => [
    'enabled'          => env('SPECTRA_BUDGET_ENABLED', true),
    'default_provider' => 'openai',
    'default_model'    => 'gpt-5.2',
],
```

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `enabled` | `bool` | `true` | Enable budget checking and enforcement. |
| `default_provider` | `string` | `openai` | Fallback provider when the budget middleware cannot determine the provider from route parameters. |
| `default_model` | `string` | `gpt-5.2` | Fallback model for cost estimation in budget checks. |

<a name="watcher"></a>
## Watcher

Configure automatic HTTP request interception.

```php
'watcher' => [
    'enabled'  => env('SPECTRA_WATCHER_ENABLED', true),
    'watchers' => [
        Spectra\Watchers\HttpWatcher::class,
        Spectra\Watchers\OpenAiWatcher::class,
        Spectra\Watchers\GuzzleWatcher::class,
    ],
],
```

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `enabled` | `bool` | `true` | Enable automatic request interception by watchers. |
| `watchers` | `array` | See above | List of watcher classes to register. Remove a class to disable that specific watcher. |

| Watcher | Intercepts | Mechanism |
| --- | --- | --- |
| `HttpWatcher` | Laravel `Http` facade requests (and Prism, Laravel AI) | Adds Spectra's Guzzle middleware as global HTTP client middleware |
| `OpenAiWatcher` | `openai-php/laravel` SDK calls | Replaces the OpenAI client with one that uses a tracked handler stack |
| `GuzzleWatcher` | Direct Guzzle HTTP client usage | Registers tracked handler stacks in the container (`spectra.guzzle.handler`, `spectra.guzzle.handler.{provider}`) |

<a name="providers"></a>
## Providers

Map provider names to their provider classes. Each provider defines hosts, endpoints, and handlers for detecting and extracting data from AI API responses.

For the full list of built-in providers, see [Models](/models#supported-providers).

```php
'providers' => [
    // Built-in providers are registered by default.
    // Add or override entries as needed.
    'acme' => ['class' => App\Spectra\AcmeProvider::class, 'name' => 'Acme AI'],
],
```

Add custom providers by extending `Provider` and adding an entry here. See [Custom Providers](/custom-providers) for a complete guide.

<a name="endpoint-urls"></a>
## Endpoint URLs

Override the base URL for any provider. This is useful when routing requests through a proxy or using a self-hosted model server:

```php
'endpoint_urls' => [
    'openai' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
    'anthropic' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
],
```

When the `laravel-ai` package is installed, Spectra will also check its config (`ai.providers.{provider}.url`) as a fallback.

<a name="azure-openai"></a>
### Azure OpenAI

Requests to Azure OpenAI and Azure AI Foundry resources are tracked as `openai` without any configuration. Spectra recognizes these hosts:

- `{resource}.openai.azure.com`
- `{resource}.cognitiveservices.azure.com`
- `{resource}.services.ai.azure.com`

Azure paths are mapped to the matching OpenAI endpoint before tracking, and the stored `endpoint` is the OpenAI path:

| Azure path | Tracked as |
| --- | --- |
| `/openai/deployments/{deployment}/chat/completions` | `/v1/chat/completions` |
| `/openai/v1/chat/completions` | `/v1/chat/completions` |
| `/openai/responses` | `/v1/responses` |

The model is taken from the response, because deployment requests don't name one. Costs use OpenAI's prices.

If your Azure endpoint uses a custom domain (for example, an API Management gateway), add it as `endpoint_urls.azure`. Its host is detected as OpenAI, but unlike `endpoint_urls.openai` it is never used as the OpenAI client's base URL:

```php
'endpoint_urls' => [
    'azure' => env('AZURE_OPENAI_URL'),
],
```

With `laravel-ai`, the host of `ai.providers.azure.url` (`AZURE_OPENAI_URL`) is detected the same way, so no Spectra config is needed.

> [!NOTE]
> The Azure AI model inference API (`/models/chat/completions`) serves models from many vendors and is not tracked.

<a name="integrations"></a>
## Integrations

Configure external integrations. Currently supports OpenTelemetry trace export. See [OpenTelemetry](/opentelemetry) for a detailed setup guide.

```php
'integrations' => [
    'opentelemetry' => [
        'enabled'             => env('SPECTRA_OTEL_ENABLED', false),
        'endpoint'            => env('SPECTRA_OTEL_ENDPOINT', 'http://localhost:4318/v1/traces'),
        'headers'             => [],
        'service_version'     => env('SPECTRA_OTEL_SERVICE_VERSION', '1.0.0'),
        'resource_attributes' => [],
        'timeout'             => env('SPECTRA_OTEL_TIMEOUT', 10),
    ],
],
```

<a name="environment-variables"></a>
## Environment Variables

A quick reference of all environment variables supported by Spectra:

```bash
# Master switch
SPECTRA_ENABLED=true

# Storage
SPECTRA_DB_CONNECTION=
SPECTRA_STORE_REQUESTS=true
SPECTRA_STORE_RESPONSES=true
SPECTRA_STORE_PROMPTS=true
SPECTRA_STORE_SYSTEM_PROMPTS=true
SPECTRA_STORE_TOOLS=true
SPECTRA_STORE_REQUEST_DATA=
SPECTRA_STORE_EMBEDDINGS=false

# Media
SPECTRA_MEDIA_ENABLED=false
SPECTRA_MEDIA_DISK=
SPECTRA_MEDIA_PATH=spectra

# Queue / persistence mode
SPECTRA_QUEUE_ENABLED=false
SPECTRA_QUEUE_CONNECTION=
SPECTRA_QUEUE_NAME=
SPECTRA_QUEUE_DELAY=
SPECTRA_AFTER_RESPONSE=false

# Dashboard
SPECTRA_DOMAIN=
SPECTRA_DASHBOARD_ENABLED=true
SPECTRA_PATH=spectra
SPECTRA_DASHBOARD_LAYOUT=full
SPECTRA_DATE_FORMAT="M j, Y g:i:s A"

# Watcher
SPECTRA_WATCHER_ENABLED=true

# Costs
SPECTRA_OPENAI_PRICING_TIER=standard
SPECTRA_ANTHROPIC_PRICING_TIER=standard

# Budget
SPECTRA_BUDGET_ENABLED=true

# Integrations
SPECTRA_OTEL_ENABLED=false
SPECTRA_OTEL_ENDPOINT=http://localhost:4318/v1/traces
SPECTRA_OTEL_SERVICE_VERSION=1.0.0
SPECTRA_OTEL_TIMEOUT=10
```
