# Configuration

All configuration lives in `config/spectra.php`, which is published when you install Spectra. You can control every option with environment variables. Below is a reference for every setting.

<a name="master-switch"></a>
## Master Switch

```php
'enabled' => env('SPECTRA_ENABLED', true),
```

When set to `false`, Spectra does nothing: no request interception, no tracking, no database writes, and no dashboard routes. Use it to turn Spectra off in specific environments without removing the package from your application.

<a name="storage"></a>
## Storage

These settings control what Spectra saves to the database, and how. Use them to balance storage use, privacy, and debugging needs.

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
| `store_system_prompts` | `bool` | `true` | Keep system prompts in the stored request body. These often contain proprietary business logic. When `false`, Spectra removes them before saving: `system` and `developer` messages, and the `system`, `instructions`, `systemInstruction` and `system_prompt` fields providers use, from the request body and from a response that echoes them (the OpenAI Responses API returns `instructions`). |
| `store_tools` | `bool` | `true` | Keep function and tool definitions in the stored request body. These can be large for complex tool schemas. When `false`, Spectra removes the `tools` and `functions` lists before saving, from the request body and from a response that echoes them (the OpenAI Responses API returns `tools`). Tool calls in responses are kept. |
| `store_request_data` | `bool\|null` | `null` | Store the raw request body sent to the provider (the full JSON body, including sampling settings) in the `request` column. When `null`, follows `store_prompts`. Set it to `true` to keep the body with `store_prompts` off; the prompts are then stored with it. When it resolves to `false`, the raw body is not saved. `store_system_prompts` and `store_tools` apply either way. |
| `store_embeddings` | `bool` | `false` | Store embedding vector values in the response column. Embedding requests are always tracked (tokens, costs, latency), but the float-vector arrays can be very large. When disabled, vectors are replaced with `[stripped]`. |

<a name="sampling"></a>
### Payload Sampling {#sampling}

Request and response bodies are most of what Spectra stores. On a busy app, keep them for a share of traces only. Every call is still recorded with its tokens, cost, latency and status, so costs, budgets, alerts and the dashboard's numbers stay exact.

```php
'storage' => [
    'sampling' => [
        'rate' => env('SPECTRA_PAYLOAD_SAMPLE_RATE', 1.0), // 0.1 keeps 10% of traces' bodies
        'keep_failures' => true,
    ],
],
```

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `rate` | `float` | `1.0` | The share of traces, from 0 to 1, whose request and response bodies are stored. |
| `keep_failures` | `bool` | `true` | Always store the bodies of failed calls, which you need most when debugging. |

All calls of a trace are kept or dropped together, picked by the trace id, so a kept trace shows its whole story. A call without a stored body says so on its request page.

<a name="redaction"></a>
### Redaction

Turn on redaction to mask personal data and secrets before Spectra stores anything: request and response bodies, error messages, [span](/usage#spans) attributes and [score](/scores) comments. A match is replaced with a label, such as `[REDACTED:email]`. Token counts and costs are not affected, and the payload keeps its shape, so the dashboard still shows it.

```php
'storage' => [
    'redaction' => [
        'enabled' => env('SPECTRA_REDACTION_ENABLED', false),
        'patterns' => ['email', 'credit_card', 'api_key', 'ip_address', 'phone'],
        'custom' => [
            'account' => '/ACC-\d{8}/',
        ],
    ],
],
```

| Pattern | Masks |
| --- | --- |
| `email` | Email addresses. |
| `credit_card` | Card numbers of 13 to 19 digits, with or without spaces or dashes, that pass the Luhn check. Other long numbers, like order ids, are kept. |
| `api_key` | OpenAI, Anthropic, AWS, GitHub, Slack and Google keys, and `Bearer` tokens. |
| `ip_address` | IPv4 addresses. |
| `phone` | International phone numbers that start with `+`. |

`custom` adds your own as `name => regex`; each match becomes `[REDACTED:name]`. For anything a regex can't express, add a callback in a service provider's `boot` method. It gets every string after the patterns have run:

```php
use Spectra\Facades\Spectra;

Spectra::redactUsing(fn (string $text) => $this->customerNames->mask($text));
```

Redaction runs before a request or span is queued, so personal data never reaches your queue either. Rows saved before you turned it on are not changed. To stop storing prompts or responses at all, use `store_prompts` and `store_responses` instead.

Whether redaction is on or not, the error message of a failed call or span is stored without URL query strings and with API keys masked: a connection error quotes the request URL, and Google's key travels in it as `?key=…`.

A redacted request in the dashboard, with the card number and email masked:

<img class="screenshot" src="/images/dashboard/request-redacted-payload.png" alt="A stored request with the card number and email replaced by labels" />

<a name="media-persistence"></a>
### Media Persistence

Some AI endpoints return media with expiring URLs. For example, DALL-E images expire after about one hour, and Sora videos include an `expires_at` timestamp. When media persistence is enabled, Spectra downloads the generated media and stores it on a Laravel filesystem disk before the URLs expire.

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
| `media.disk` | `string\|null` | `null` | Laravel filesystem disk to store media on. `null` uses the default filesystem disk. In production, use `s3` or another cloud disk so every server can reach the files. |
| `media.path` | `string` | `spectra` | Directory path within the disk. |

> [!NOTE]
> **Binary data is always stripped from stored requests and responses.** Whether or not media persistence is enabled, Spectra replaces inline base64-encoded data with `[stripped]` placeholders before saving requests and responses to the database. This keeps multi-megabyte base64 strings out of the `request` and `response` columns. Spectra strips these formats automatically:
> - Any `data:` URI in a request body (the base64 image or audio file of a vision or audio prompt), wherever the provider puts it
> - OpenAI DALL-E: `data[].b64_json`
> - OpenAI Responses API: `output[].result` (image generation calls)
> - Google Gemini: `candidates[].content.parts[].inlineData.data` (images and audio)
> - Google Imagen: `generatedImages[].image.imageBytes`
>
> To keep the actual media files, enable media persistence above. Spectra then decodes the files and stores them on disk, and the dashboard serves them from the `media_storage_path` column.

Media files are stored as flat file paths in the `media_storage_path` JSON column (e.g. `["spectra/abc123/0.png", "spectra/abc123/1.png"]`). The dashboard serves images and audio directly from these paths via internal API routes. When [`spectra:prune`](/commands#spectra-prune) deletes a request, it deletes the request's stored media files too.

<a name="api-keys"></a>
## API Keys

You can set an API key for each provider here. Spectra uses these keys for its own calls, such as media downloads, [Playground](/playground) runs and counting Google embedding tokens (with the `countTokens` API). A key set here wins over every other config location.

To find a key, Spectra checks several config locations for each provider, in order. For Google, it checks `spectra.api_keys.google` → `services.google.api_key` → `services.google.key` → `prism.providers.gemini.api_key` → `ai.providers.google.key`, and uses the first key it finds.

```php
'api_keys' => [
    'openai'    => env('SPECTRA_OPENAI_API_KEY'),
    'anthropic' => env('SPECTRA_ANTHROPIC_API_KEY'),
    // ...
],
```

<a name="queue"></a>
## Queue

Choose whether Spectra saves requests synchronously, after the HTTP response is sent, or in a background queue job.

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

Choose what context Spectra captures automatically with each tracked request.

```php
'tracking' => [
    'auto_track_user'    => true,
    'capture_ip'         => true,
    'capture_user_agent' => true,
    'capture_request_id' => true,
    'capture_origin'     => true,
    'root_spans'         => true,
    'tool_spans'         => false,
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
| `capture_request_id` | `bool` | `true` | Store a request ID for correlation with your logs in the `laravel_request_id` column. Uses the `X-Request-ID` header if present (cut to 255 characters), otherwise generates one. |
| `capture_origin` | `bool` | `true` | Store where each call came from in the `origin` column: the queued job class, the Artisan command (`artisan reports:send`), or the route pattern of the HTTP request (`POST /chats/{chat}`). Only the pattern is stored, never the URL with its ids and query string. Env: `SPECTRA_CAPTURE_ORIGIN`. |
| `root_spans` | `bool` | `true` | Give each HTTP request, queued job and Artisan command that makes AI calls a span of its own, with its calls and spans inside. Only units of work that made an AI call are stored. Needs `trace_per_request`: with it off, each call is a trace of its own and no root span is stored. Env: `SPECTRA_ROOT_SPANS`. See [Spans](/usage#spans). |
| `tool_spans` | `bool` | `false` | Draw the tools a model asked for in the trace: when a call sends tool results back, a `tool` span per result, with its arguments and result. Tools run through `Spectra::tool()` are recorded either way. Env: `SPECTRA_TOOL_SPANS`. See [Tools](/tools#tools-the-model-asked-for). |
| `trace_per_request` | `bool` | `true` | Give every AI call of the same HTTP request, queued job, or Artisan command one trace id. Queued jobs dispatched during a request keep its trace. Turning it off also turns off `root_spans`. Env: `SPECTRA_TRACE_PER_REQUEST`. See [Traces](/usage#traces). |
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
    'maintenance' => env('SPECTRA_DASHBOARD_MAINTENANCE', true),
    'playground'  => [
        'enabled' => env('SPECTRA_PLAYGROUND_ENABLED', false),
        'timeout' => env('SPECTRA_PLAYGROUND_TIMEOUT', 120),
    ],
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
| `maintenance` | `bool` | `true` | Allow the [Maintenance screen](/dashboard#maintenance) to prune, rebuild stats and pause recording. Running them also needs the `manageSpectra` gate. When `false`, the screen is read-only. |
| `playground.enabled` | `bool` | `false` | Let the [Playground](/playground) send new requests, and stored calls again with edits, to a provider. Each run uses your API key and costs money. Using it also needs the `manageSpectra` gate. |
| `playground.timeout` | `int` | `120` | How long a Playground run may wait for the provider, in seconds. |
| `layout` | `string` | `full` | Which model types the dashboard displays. Options: `full`, `text`, `embedding`, `image`, `video`, `audio`. |
| `date_format` | `string` | `M j, Y g:i:s A` | PHP date format string for timestamps in the dashboard. |

<a name="dashboards"></a>
## Shared Dashboards {#dashboards}

Configure [shared dashboards](/dashboards), the read-only pages you share with people outside Spectra.

```php
'dashboards' => [
    'enabled'    => env('SPECTRA_DASHBOARDS_ENABLED', false),
    'middleware' => ['web', 'auth', 'throttle:60,1'],
    'domain'     => env('SPECTRA_DASHBOARDS_DOMAIN'),
],
```

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `enabled` | `bool` | `false` | Turn shared dashboards on. They give people who can't open Spectra's own dashboard access to data. Designing one also needs the `manageSpectra` gate. |
| `middleware` | `array` | `['web', 'auth', 'throttle:60,1']` | Middleware on the pages viewers open, in place of `dashboard.middleware`. Keep `auth`, naming your guard if viewers use another one (`auth:customer`). |
| `domain` | `string\|null` | `null` | Serve the pages on this domain. `null` answers on any. |

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

    'pricing_path' => env('SPECTRA_PRICING_PATH'),

    'cache' => [
        'enabled' => (bool) env('SPECTRA_PRICING_CACHE', true),
        'store'   => env('SPECTRA_PRICING_CACHE_STORE'),
        'ttl'     => (int) env('SPECTRA_PRICING_CACHE_TTL', 86400),
    ],

    'firecrawl' => [
        'api_key'   => env('SPECTRA_FIRECRAWL_API_KEY', env('FIRECRAWL_API_KEY')),
        'url'       => env('SPECTRA_FIRECRAWL_API_URL', env('FIRECRAWL_API_URL', 'https://api.firecrawl.dev/v2')),
        'timeout'   => (int) env('SPECTRA_FIRECRAWL_TIMEOUT', env('FIRECRAWL_TIMEOUT', 180)),
        'providers' => array_values(array_filter(array_map('trim', explode(',', (string) env('SPECTRA_SCRAPE_PROVIDERS', ''))))),
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
| `pricing_path` | `string\|null` | `null` | Your own pricing file (JSON), in the format of the [bundled catalog](/pricing#the-pricing-file). Each model in it replaces the bundled model with the same id; every other model keeps its bundled price. A relative path is relative to the app's root. `spectra:pricing-scrape` writes it. See [Your Own Prices](/pricing#your-own-prices). |
| `cache.enabled` | `bool` | `true` | Keep the pricing index in the cache instead of parsing the pricing files once per process. It is rebuilt when a file changes. See [Caching](/pricing#caching). |
| `cache.store` | `string\|null` | `null` | The cache store to keep it in, from `config/cache.php`. `null` is your default store. |
| `cache.ttl` | `int` | `86400` | Seconds to keep the index. `0` keeps it until a file changes. |
| `firecrawl.*` | `array` | See above | API key, base URL, per-page timeout (seconds) and the default providers for [`spectra:pricing-scrape`](/pricing#scraping-prices), which saves the prices that differ from the bundled catalog to `pricing_path`. Without `providers`, it scrapes the providers your app has tracked requests for. Tracking never calls Firecrawl. The unprefixed `FIRECRAWL_API_KEY`, `FIRECRAWL_API_URL` and `FIRECRAWL_TIMEOUT` still work when the `SPECTRA_` names are not set. |

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
| `default_provider` | `string` | `openai` | The provider the `spectra.budget` middleware checks when the route names none (`spectra.budget:anthropic`). Request input is never used. It matters only for budgets with allowed providers. |
| `default_model` | `string` | `gpt-5.2` | The model the `spectra.budget` middleware checks when the route names none (`spectra.budget:openai,gpt-4o`). Request input is never used. It matters only for budgets with allowed models. |

<a name="alerts"></a>
## Alerts

Rules `spectra:check-alerts` checks, and where it sends notifications. See [Alerts](/alerts).

```php
'alerts' => [
    'rules' => [
        ['name' => 'High error rate', 'metric' => 'error_rate', 'above' => 0.1, 'window' => 15],
    ],
    'cooldown' => env('SPECTRA_ALERTS_COOLDOWN', 60),
    'notify' => [
        'mail' => env('SPECTRA_ALERTS_MAIL'),
        'slack_webhook_url' => env('SPECTRA_ALERTS_SLACK_WEBHOOK_URL'),
        'webhook_url' => env('SPECTRA_ALERTS_WEBHOOK_URL'),
    ],
],
```

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `rules` | `array` | `[]` | The alert rules: `name`, `metric`, `above` or `below`, and optionally `window`, `min_requests`, `provider` and `model`. |
| `cooldown` | `int` | `60` | Minutes before a rule that keeps firing notifies again. |
| `notify.mail` | `string` | `null` | Comma-separated email addresses. |
| `notify.slack_webhook_url` | `string` | `null` | A Slack incoming webhook. |
| `notify.webhook_url` | `string` | `null` | A URL that receives a JSON POST per firing and recovery. |

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
| `watchers` | `array` | See above | List of watcher classes to register. Remove a class to disable that specific watcher. A class that doesn't exist is reported to your exception handler and skipped. |

| Watcher | Intercepts | Mechanism |
| --- | --- | --- |
| `HttpWatcher` | Laravel `Http` facade requests (and Prism, Laravel AI) | Adds Spectra's Guzzle middleware as global HTTP client middleware |
| `OpenAiWatcher` | `openai-php/laravel` SDK calls | Replaces the OpenAI client with one that uses a tracked handler stack |
| `GuzzleWatcher` | Direct Guzzle HTTP client usage | Registers tracked handler stacks in the container (`spectra.guzzle.handler`, `spectra.guzzle.handler.{provider}`) |

<a name="providers"></a>
## Providers

Map provider names to their provider classes. Each provider defines the hosts, endpoints, and handlers Spectra uses to detect AI API responses and extract their data.

For the full list of built-in providers, see [Models](/models#supported-providers).

```php
'providers' => [
    // Built-in providers stay active even when they are not listed here.
    // Add or override entries as needed.
    'acme' => ['class' => App\Spectra\AcmeProvider::class, 'name' => 'Acme AI'],

    // Stop tracking a built-in provider.
    'ollama' => false,
],
```

Add custom providers by extending `Provider` and adding an entry here. See [Custom Providers](/custom-providers) for a complete guide.

Your published `providers` list and `costs.pricing` list are merged over the built-in ones: your entries win, and built-in entries you don't list stay active, so providers added in a later Spectra release work without republishing the config. Set an entry to `false` to turn a built-in off.

<a name="endpoint-urls"></a>
## Endpoint URLs

Override the base URL for any provider, for example when you route requests through a proxy or use a self-hosted model server:

```php
'endpoint_urls' => [
    'openai' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
    'anthropic' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
],
```

When the `laravel-ai` package is installed, Spectra also checks its config (`ai.providers.{provider}.url`) as a fallback.

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

The model is taken from the response, because deployment requests don't name one. When the response names no model either (image generation, text to speech, transcription), the deployment's name stands in for it, so name those deployments after their model (`dall-e-3`, `tts-1`) to have them priced; a deployment named otherwise is recorded under that name and reported as [unpriced](/costs#zero-cost-requests). Costs use OpenAI's prices.

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

Configure external integrations. Spectra currently supports exporting traces to OpenTelemetry. See [OpenTelemetry](/opentelemetry) for the setup guide.

```php
'integrations' => [
    'request_transformer' => \Spectra\Support\RequestTransformer::class,

    'opentelemetry' => [
        'enabled'             => env('SPECTRA_OTEL_ENABLED', false),
        'endpoint'            => env('SPECTRA_OTEL_ENDPOINT', 'http://localhost:4318/v1/traces'),
        'headers'             => [],
        'service_version'     => env('SPECTRA_OTEL_SERVICE_VERSION', '1.0.0'),
        'resource_attributes' => [],
        'timeout'             => (int) env('SPECTRA_OTEL_TIMEOUT', 10),
        'span_builder'        => null,
    ],
],
```

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `request_transformer` | `string` | `RequestTransformer::class` | The class that turns a tracked request into the array the `RequestTracked` event and the exporters receive. Extend `Spectra\Support\RequestTransformer` to add or remove fields. |
| `opentelemetry.enabled` | `bool` | `false` | Export every tracked request as an OpenTelemetry span. |
| `opentelemetry.endpoint` | `string` | `http://localhost:4318/v1/traces` | The OTLP/HTTP traces endpoint of your collector or vendor. |
| `opentelemetry.headers` | `array` | `[]` | Extra HTTP headers sent with each export, such as an API key. |
| `opentelemetry.service_version` | `string` | `1.0.0` | The `service.version` resource attribute. `service.name` is your `app.name`. |
| `opentelemetry.resource_attributes` | `array` | `[]` | Extra resource attributes added to every trace, such as `deployment.environment`. |
| `opentelemetry.timeout` | `int` | `10` | Seconds to wait for the collector. A failed export is logged and never affects your app. |
| `opentelemetry.span_builder` | `string\|null` | `null` | A class implementing `Spectra\Contracts\SpanBuilder` that builds the spans. `null` uses the built-in builder, which follows the OpenTelemetry GenAI conventions. See [OpenTelemetry](/opentelemetry). |

<a name="environment-variables"></a>
## Environment Variables

Every environment variable Spectra supports, in one place:

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

# Tracking context
SPECTRA_CAPTURE_ORIGIN=true
SPECTRA_ROOT_SPANS=true
SPECTRA_TOOL_SPANS=false
SPECTRA_TRACE_PER_REQUEST=true
SPECTRA_USE_TRACEPARENT=true
SPECTRA_PROPAGATE_TAGS=true

# Dashboard
SPECTRA_DOMAIN=
SPECTRA_DASHBOARD_ENABLED=true
SPECTRA_PATH=spectra
SPECTRA_DASHBOARD_MAINTENANCE=true
SPECTRA_PLAYGROUND_ENABLED=false
SPECTRA_PLAYGROUND_TIMEOUT=120
SPECTRA_DASHBOARD_LAYOUT=full
SPECTRA_DATE_FORMAT="M j, Y g:i:s A"

# Shared dashboards
SPECTRA_DASHBOARDS_ENABLED=false
SPECTRA_DASHBOARDS_DOMAIN=

# Watcher
SPECTRA_WATCHER_ENABLED=true

# Costs
SPECTRA_OPENAI_PRICING_TIER=standard
SPECTRA_ANTHROPIC_PRICING_TIER=standard
SPECTRA_PRICING_PATH=
SPECTRA_PRICING_CACHE=true
SPECTRA_PRICING_CACHE_STORE=
SPECTRA_PRICING_CACHE_TTL=86400
SPECTRA_FIRECRAWL_API_KEY=
SPECTRA_FIRECRAWL_API_URL=https://api.firecrawl.dev/v2
SPECTRA_FIRECRAWL_TIMEOUT=180
SPECTRA_SCRAPE_PROVIDERS=

# Budget
SPECTRA_BUDGET_ENABLED=true

# Integrations
SPECTRA_OTEL_ENABLED=false
SPECTRA_OTEL_ENDPOINT=http://localhost:4318/v1/traces
SPECTRA_OTEL_SERVICE_VERSION=1.0.0
SPECTRA_OTEL_TIMEOUT=10

# Payload sampling and redaction
SPECTRA_PAYLOAD_SAMPLE_RATE=1.0
SPECTRA_REDACTION_ENABLED=false

# Alerts
SPECTRA_ALERTS_COOLDOWN=60
SPECTRA_ALERTS_MAIL=
SPECTRA_ALERTS_SLACK_WEBHOOK_URL=
SPECTRA_ALERTS_WEBHOOK_URL=
```
