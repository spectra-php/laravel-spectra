<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Spectra Master Switch
    |--------------------------------------------------------------------------
    |
    | This option may be used to disable AI observability entirely. When set
    | to false, no requests will be tracked and no data will be stored. This
    | is useful for local development or when you want to temporarily disable
    | observability without removing the package.
    |
    */

    'enabled' => env('SPECTRA_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how AI request data is stored. You can use a separate database
    | connection for Spectra data, control what content is stored, and set
    | limits on content length to manage storage size.
    |
    */

    'storage' => [

        /*
        |--------------------------------------------------------------------------
        | Database Connection
        |--------------------------------------------------------------------------
        |
        | The database connection to use for Spectra tables. Leave null to use
        | your application's default connection. You may want to use a separate
        | connection for high-volume applications to avoid impacting your main
        | database performance.
        |
        */

        'connection' => env('SPECTRA_DB_CONNECTION'),

        /*
        |--------------------------------------------------------------------------
        | Store Requests
        |--------------------------------------------------------------------------
        |
        | Whether to store AI request records in the database. When disabled,
        | requests will still be tracked in memory (for callbacks, events, etc.)
        | but will not be persisted to the database. Useful when you only want
        | real-time monitoring without historical data storage.
        |
        */

        'store_requests' => env('SPECTRA_STORE_REQUESTS', true),

        /*
        |--------------------------------------------------------------------------
        | Store Responses
        |--------------------------------------------------------------------------
        |
        | Whether to store the AI's response content. Disable this if you only
        | need usage metrics without storing the actual generated content.
        | Useful for privacy compliance or reducing storage costs.
        |
        */

        'store_responses' => env('SPECTRA_STORE_RESPONSES', true),

        /*
        |--------------------------------------------------------------------------
        | Store Prompts
        |--------------------------------------------------------------------------
        |
        | Whether to store the user's prompt text and chat messages. Disable this
        | if you have privacy concerns or want to reduce storage usage. When
        | disabled, you'll still see token counts and costs, but not the actual
        | content sent to the AI.
        |
        */

        'store_prompts' => env('SPECTRA_STORE_PROMPTS', true),

        /*
        |--------------------------------------------------------------------------
        | Store System Prompts
        |--------------------------------------------------------------------------
        |
        | Whether to store system prompts separately. System prompts often contain
        | sensitive business logic or instructions. Disable this if you want to
        | keep your AI configuration private.
        |
        */

        'store_system_prompts' => env('SPECTRA_STORE_SYSTEM_PROMPTS', true),

        /*
        |--------------------------------------------------------------------------
        | Store Tool Definitions
        |--------------------------------------------------------------------------
        |
        | Whether to store function/tool definitions sent to the AI. Tool schemas
        | can be large and may contain sensitive API documentation. Disable this
        | to reduce storage or protect your tool implementations.
        |
        */

        'store_tools' => env('SPECTRA_STORE_TOOLS', true),

        /*
        |--------------------------------------------------------------------------
        | Store Embeddings
        |--------------------------------------------------------------------------
        |
        | Whether to store embedding vector values in the response column.
        | Embedding requests are always tracked (tokens, costs, latency), but
        | the actual float-vector arrays can be very large. When disabled, the
        | vectors are replaced with [stripped] placeholders before storage.
        |
        */

        'store_embeddings' => env('SPECTRA_STORE_EMBEDDINGS', false),

        /*
        |--------------------------------------------------------------------------
        | Media Persistence
        |--------------------------------------------------------------------------
        |
        | Some AI endpoints return media with expiring URLs (e.g. DALL-E images
        | expire after ~1 hour, Sora videos have an expires_at timestamp). When
        | enabled, Spectra will download and persist generated media files to a
        | Laravel filesystem disk before the URLs expire.
        |
        */

        'media' => [
            'enabled' => env('SPECTRA_MEDIA_ENABLED', false),
            'disk' => env('SPECTRA_MEDIA_DISK'),
            'path' => env('SPECTRA_MEDIA_PATH', 'spectra'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | API Keys
    |--------------------------------------------------------------------------
    |
    | Optional explicit API keys per provider. These take highest priority when
    | Spectra needs to make authenticated requests (e.g. downloading Sora videos).
    | If left null, Spectra auto-discovers keys from common config locations:
    | OpenAI SDK (openai.api_key), services.* config, and Prism providers.
    | Example; 'openai' => env('SPECTRA_OPENAI_API_KEY'),
    |
    */

    'api_keys' => [
        // ...
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider Endpoint URLs
    |--------------------------------------------------------------------------
    |
    | Optional custom base URLs per provider. Use these when routing requests
    | through a proxy, gateway, or self-hosted endpoint instead of the
    | provider's default API URL. If left null, the provider's default
    | endpoint is used.
    |
    | When the laravel-ai package is installed, Spectra will also check
    | its config (ai.providers.{provider}.url) as a fallback.
    |
    | Example:
    |   'openai' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
    |   'anthropic' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
    |
    */

    'endpoint_urls' => [
        // ...
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configure whether to persist AI request data via a queue job instead of
    | synchronously. This can improve response times for your application by
    | deferring database writes to a background worker.
    |
    */

    'queue' => [

        /*
        |--------------------------------------------------------------------------
        | Queue Enabled
        |--------------------------------------------------------------------------
        |
        | When enabled, AI request data will be dispatched to a queue job for
        | persistence instead of being saved synchronously. This requires a
        | running queue worker to process the jobs.
        |
        */

        'enabled' => env('SPECTRA_QUEUE_ENABLED', false),

        /*
        |--------------------------------------------------------------------------
        | Queue Connection
        |--------------------------------------------------------------------------
        |
        | The queue connection to use for persisting AI requests. Leave null to
        | use your application's default queue connection. You may want to use
        | a dedicated connection to separate AI observability work from your
        | application's main queue.
        |
        */

        'connection' => env('SPECTRA_QUEUE_CONNECTION'),

        /*
        |--------------------------------------------------------------------------
        | Queue Name
        |--------------------------------------------------------------------------
        |
        | The queue name to dispatch persistence jobs to. Use a separate queue
        | to prioritize or isolate AI observability work from other jobs.
        |
        */

        'queue' => env('SPECTRA_QUEUE_NAME'),

        /*
        |--------------------------------------------------------------------------
        | Delay
        |--------------------------------------------------------------------------
        |
        | The number of seconds to delay before processing the persistence job.
        | This can be useful to batch writes or reduce load during peak times.
        | Leave null for no delay.
        |
        */

        'delay' => env('SPECTRA_QUEUE_DELAY'),

        /*
        |--------------------------------------------------------------------------
        | After Response
        |--------------------------------------------------------------------------
        |
        | When enabled (and queue is disabled), AI request data will be persisted
        | after the HTTP response is sent to the client. This improves response
        | times without requiring a queue worker. Has no effect when queue is
        | enabled or when running in a non-HTTP context (e.g. console commands).
        |
        */

        'after_response' => env('SPECTRA_AFTER_RESPONSE', false),

    ],

    /*
    |--------------------------------------------------------------------------
    | Tracking Context
    |--------------------------------------------------------------------------
    |
    | Configure automatic context tracking for AI requests. Spectra can
    | automatically capture information about the request context to help
    | you understand how your AI features are being used.
    |
    */

    'tracking' => [

        /*
        |--------------------------------------------------------------------------
        | Auto-Track User
        |--------------------------------------------------------------------------
        |
        | When enabled, Spectra will automatically associate AI requests with the
        | currently authenticated user. This allows you to see which users are
        | making requests and analyze per-user usage patterns.
        |
        */

        'auto_track_user' => true,

        /*
        |--------------------------------------------------------------------------
        | Capture IP Address
        |--------------------------------------------------------------------------
        |
        | Whether to capture the client's IP address. Useful for security audits
        | and geographic analysis. Disable this for privacy compliance (GDPR,
        | CCPA) or if you don't need location-based analytics.
        |
        */

        'capture_ip' => true,

        /*
        |--------------------------------------------------------------------------
        | Capture User Agent
        |--------------------------------------------------------------------------
        |
        | Whether to capture the client's user agent string. Useful for
        | understanding which browsers/devices are using your AI features.
        | Disable this for privacy compliance if needed.
        |
        */

        'capture_user_agent' => true,

        /*
        |--------------------------------------------------------------------------
        | Capture Request ID
        |--------------------------------------------------------------------------
        |
        | Whether to capture or generate a request ID for correlation with your
        | application logs. This helps link AI requests to specific HTTP requests
        | in your logging system. Uses X-Request-ID header if available.
        |
        */

        'capture_request_id' => true,

    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the Spectra dashboard. The dashboard provides a web interface
    | for viewing AI request history, usage statistics, and cost analytics.
    |
    */

    'dashboard' => [

        /*
        |--------------------------------------------------------------------------
        | Spectra Domain
        |--------------------------------------------------------------------------
        |
        | This is the subdomain where Spectra will be accessible from. If this
        | setting is null, Spectra will reside under the same domain as the
        | application. Otherwise, this value will serve as the subdomain.
        |
        | Example: 'spectra' would make the dashboard available at spectra.yourdomain.com
        |
        */

        'domain' => env('SPECTRA_DOMAIN'),

        /*
        |--------------------------------------------------------------------------
        | Dashboard Enabled
        |--------------------------------------------------------------------------
        |
        | Whether to enable the web dashboard. You may want to disable this in
        | production if you prefer to access data through the API or if you're
        | using a separate monitoring solution.
        |
        */

        'enabled' => env('SPECTRA_DASHBOARD_ENABLED', true),

        /*
        |--------------------------------------------------------------------------
        | Dashboard Path
        |--------------------------------------------------------------------------
        |
        | The URI path where the dashboard will be accessible. For example, if
        | set to 'spectra', the dashboard will be at https://yourdomain.com/spectra
        |
        */

        'path' => env('SPECTRA_PATH', 'spectra'),

        /*
        |--------------------------------------------------------------------------
        | Dashboard Middleware
        |--------------------------------------------------------------------------
        |
        | The middleware groups to apply to the dashboard routes. By default,
        | only the 'web' middleware is applied. Add 'auth' or custom middleware
        | to restrict access to authorized users.
        |
        | Example: ['web', 'auth', 'verified']
        |
        */

        'middleware' => ['web'],

        /*
        |--------------------------------------------------------------------------
        | Dashboard Layout
        |--------------------------------------------------------------------------
        |
        | Controls which model types the dashboard displays stats for:
        |
        | - 'full': Show all model types with type distribution chart
        | - 'text': Show only text completion metrics
        | - 'embedding': Show only embedding metrics
        | - 'image': Show only image generation metrics
        | - 'video': Show only video generation metrics
        | - 'audio': Show both TTS and STT metrics
        |
        */

        'layout' => env('SPECTRA_DASHBOARD_LAYOUT', 'full'),

        /*
        |--------------------------------------------------------------------------
        | Date Format
        |--------------------------------------------------------------------------
        |
        | The date format used to display timestamps in the dashboard. Uses PHP
        | date format syntax (e.g. Carbon). All date formatting is done server-side
        | so that formats are consistent and timezone-aware.
        |
        | Common formats:
        | - 'M j, Y g:i A'      → Jan 7, 2026 3:45 PM
        | - 'Y-m-d H:i:s'       → 2026-01-07 15:45:30
        | - 'd/m/Y H:i'         → 07/01/2026 15:45
        | - 'F j, Y g:i:s A'    → January 7, 2026 3:45:30 PM
        |
        */

        'date_format' => env('SPECTRA_DATE_FORMAT', 'M j, Y g:i:s A'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Cost Configuration
    |--------------------------------------------------------------------------
    |
    | Configure cost tracking and custom pricing overrides. Spectra includes
    | built-in pricing for popular models, but you can override these values
    | if you have negotiated custom rates or need to track internal costs.
    |
    */

    'costs' => [

        /*
        |--------------------------------------------------------------------------
        | Cost Tracking Enabled
        |--------------------------------------------------------------------------
        |
        | Whether to calculate and store costs for AI requests. When enabled,
        | Spectra will calculate costs based on token usage and model pricing.
        |
        */

        'enabled' => true,

        /*
        |--------------------------------------------------------------------------
        | Currency
        |--------------------------------------------------------------------------
        |
        | The currency code for cost display. All costs are stored in cents
        | of this currency. Only affects display; does not convert prices.
        |
        */

        'currency' => 'USD',

        /*
        |--------------------------------------------------------------------------
        | Currency Symbol
        |--------------------------------------------------------------------------
        |
        | The symbol to display before cost values in the dashboard. Common
        | symbols: '$' (USD), '€' (EUR), '£' (GBP), '¥' (JPY/CNY).
        |
        */

        'currency_symbol' => '$',

        /*
        |--------------------------------------------------------------------------
        | Provider Settings
        |--------------------------------------------------------------------------
        |
        | Per-provider configuration for cost calculation. Each provider can have
        | its own settings that affect pricing.
        |
        | OpenAI supports pricing tiers:
        | - 'batch': Lowest price, for async processing with up to 24-hour turnaround
        | - 'flex': Lower price with higher latency, for flexible workloads
        | - 'standard': Regular pricing, standard latency (default)
        | - 'priority': Higher price for faster processing and guaranteed capacity
        |
        */

        'provider_settings' => [
            'openai' => [
                'default_tier' => env('SPECTRA_OPENAI_PRICING_TIER', 'standard'),
            ],
            'anthropic' => [
                'default_tier' => env('SPECTRA_ANTHROPIC_PRICING_TIER', 'standard'),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Pricing Definitions
        |--------------------------------------------------------------------------
        |
        | Map provider slugs to their pricing definition classes. Each class
        | defines models and their pricing tiers. Spectra ships with built-in
        | pricing for all supported providers.
        |
        | To add custom models, extend a built-in class and override populate():
        |
        |   class MyOpenAIPricing extends \Spectra\Pricing\OpenAIPricing {
        |       protected function populate(): void {
        |           $this->model('ft:gpt-4o:my-org', fn ($m) => $m
        |               ->displayName('My Fine-tune')
        |               ->canGenerateText()
        |               ->cost(300, 1200));
        |       }
        |   }
        |
        | Then replace the openai entry with your class.
        |
        */

        'pricing' => [
            'openai' => \Spectra\Pricing\OpenAIPricing::class,
            'anthropic' => \Spectra\Pricing\AnthropicPricing::class,
            'google' => \Spectra\Pricing\GooglePricing::class,
            'xai' => \Spectra\Pricing\XAiPricing::class,
            'mistral' => \Spectra\Pricing\MistralPricing::class,
            'openrouter' => \Spectra\Pricing\OpenRouterPricing::class,
            'replicate' => \Spectra\Pricing\ReplicatePricing::class,
            'cohere' => \Spectra\Pricing\CoherePricing::class,
            'groq' => \Spectra\Pricing\GroqPricing::class,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Budget Configuration
    |--------------------------------------------------------------------------
    |
    | Configure budget enforcement for AI requests. Budgets allow you to set
    | spending limits per user, team, or globally. You can enforce hard limits
    | that block requests or soft limits that only send warnings.
    |
    */

    'budget' => [

        /*
        |--------------------------------------------------------------------------
        | Budget Enforcement Enabled
        |--------------------------------------------------------------------------
        |
        | Whether to enable budget checking and enforcement. When disabled,
        | budget limits will not be checked and all requests will be allowed.
        |
        */

        'enabled' => env('SPECTRA_BUDGET_ENABLED', true),

        /*
        |--------------------------------------------------------------------------
        | Default Provider
        |--------------------------------------------------------------------------
        |
        | The default AI provider to use when checking budgets if not specified
        | in the request. Used by the budget middleware when provider cannot
        | be determined from the request context.
        |
        */

        'default_provider' => 'openai',

        /*
        |--------------------------------------------------------------------------
        | Default Model
        |--------------------------------------------------------------------------
        |
        | The default model to use when checking budgets if not specified in
        | the request. Used by the budget middleware for cost estimation when
        | the exact model cannot be determined.
        |
        */

        'default_model' => 'gpt-4',

    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic HTTP Watcher
    |--------------------------------------------------------------------------
    |
    | Configure the automatic HTTP watcher that intercepts requests to AI
    | providers without requiring manual tracking calls. This watches all
    | outgoing HTTP requests and automatically tracks those going to known
    | AI providers.
    |
    */

    'watcher' => [

        /*
        |--------------------------------------------------------------------------
        | Watcher Enabled
        |--------------------------------------------------------------------------
        |
        | Whether to enable automatic request interception. When enabled,
        | all requests to known AI providers will be automatically tracked
        | without requiring manual Spectra::track() calls.
        |
        */

        'enabled' => env('SPECTRA_WATCHER_ENABLED', true),

        /*
        |--------------------------------------------------------------------------
        | Watchers
        |--------------------------------------------------------------------------
        |
        | The watcher classes to register. Each watcher intercepts AI requests
        | through a different mechanism:
        |
        | - HttpWatcher: Intercepts requests made via Laravel's Http facade
        | - OpenAiWatcher: Intercepts requests made via the openai-php/laravel SDK
        | - GuzzleWatcher: Provides tracked Guzzle handler stacks for direct Guzzle usage
        |
        | Remove a watcher from this list to disable it.
        |
        */

        'watchers' => [
            Spectra\Watchers\HttpWatcher::class,
            Spectra\Watchers\OpenAiWatcher::class,
            Spectra\Watchers\GuzzleWatcher::class,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Map provider names to their provider classes. Providers handle extracting
    | usage data from provider-specific response formats. Each provider defines
    | a schema for extracting tokens, content, and metadata from API responses.
    |
    | You can add custom providers for providers not included by default, or
    | override existing providers with your own implementations.
    |
    | To create a custom provider, extend Spectra\Providers\Provider
    | and implement the handlers() method returning Handler instances that
    | know how to extract data from your provider's response format.
    |
    */

    'providers' => [
        'openai' => ['class' => Spectra\Providers\OpenAI\OpenAI::class, 'name' => 'OpenAI'],
        'anthropic' => ['class' => Spectra\Providers\Anthropic\Anthropic::class, 'name' => 'Anthropic'],
        'google' => ['class' => Spectra\Providers\Google\Google::class, 'name' => 'Google AI'],
        'ollama' => ['class' => Spectra\Providers\Ollama\Ollama::class, 'name' => 'Ollama'],
        'openrouter' => ['class' => Spectra\Providers\OpenRouter\OpenRouter::class, 'name' => 'OpenRouter'],
        'cohere' => ['class' => Spectra\Providers\Cohere\Cohere::class, 'name' => 'Cohere'],
        'groq' => ['class' => Spectra\Providers\Groq\Groq::class, 'name' => 'Groq'],
        'xai' => ['class' => Spectra\Providers\XAi\XAi::class, 'name' => 'xAI'],
        'elevenlabs' => ['class' => Spectra\Providers\ElevenLabs\ElevenLabs::class, 'name' => 'ElevenLabs'],
        'replicate' => ['class' => Spectra\Providers\Replicate\Replicate::class, 'name' => 'Replicate'],
        'mistral' => ['class' => Spectra\Providers\Mistral\Mistral::class, 'name' => 'Mistral'],
    ],

    /*
    |--------------------------------------------------------------------------
    | External Integrations
    |--------------------------------------------------------------------------
    |
    | Configure integrations with external observability and debugging tools.
    | Spectra can export traces to OpenTelemetry-compatible backends for
    | enterprise observability.
    |
    */

    'integrations' => [

        /*
        |--------------------------------------------------------------------------
        | Request Transformer
        |--------------------------------------------------------------------------
        |
        | Controls how SpectraRequest models are transformed into clean data
        | arrays for integrations and events. Extend RequestTransformer to add
        | or remove fields from the data that integrations receive.
        |
        | Override with your own class to add or remove fields.
        |
        */

        'request_transformer' => Spectra\Support\RequestTransformer::class,

        /*
        |--------------------------------------------------------------------------
        | OpenTelemetry Integration
        |--------------------------------------------------------------------------
        |
        | Export AI operation traces in OpenTelemetry format to any OTLP-compatible
        | backend. This enables integration with enterprise observability stacks:
        |
        | - Jaeger, Zipkin (open source)
        | - AWS X-Ray, Google Cloud Trace, Azure Monitor
        | - Datadog APM, New Relic, Splunk, Dynatrace
        | - Grafana Tempo, Honeycomb, Lightstep
        |
        */

        'opentelemetry' => [

            /*
            | Enable/disable OpenTelemetry export. When disabled, no traces
            | will be exported even if an endpoint is configured.
            */
            'enabled' => env('SPECTRA_OTEL_ENABLED', false),

            /*
            | The OTLP HTTP endpoint for trace export. Most collectors accept
            | traces at /v1/traces. Common endpoints:
            |
            | - Local collector: http://localhost:4318/v1/traces
            | - Jaeger: http://localhost:4318/v1/traces
            | - Grafana Tempo: http://tempo:4318/v1/traces
            |
            | For cloud providers, use their specific ingest endpoints.
            */
            'endpoint' => env('SPECTRA_OTEL_ENDPOINT', 'http://localhost:4318/v1/traces'),

            /*
            | Custom headers to include with OTLP requests. Useful for
            | authentication with cloud providers. Example:
            |
            | 'headers' => [
            |     'Authorization' => 'Bearer ' . env('OTEL_AUTH_TOKEN'),
            |     'x-api-key' => env('OTEL_API_KEY'),
            | ],
            */
            'headers' => [],

            /*
            | Service version to include in trace metadata. Helps identify
            | which version of your application generated the trace.
            */
            'service_version' => env('SPECTRA_OTEL_SERVICE_VERSION', '1.0.0'),

            /*
            | Additional resource attributes to include in all traces.
            | Useful for adding deployment-specific metadata.
            |
            | 'resource_attributes' => [
            |     'deployment.region' => 'us-east-1',
            |     'k8s.namespace' => 'production',
            | ],
            */
            'resource_attributes' => [],

            /*
            | Timeout in seconds for OTLP HTTP requests.
            */
            'timeout' => env('SPECTRA_OTEL_TIMEOUT', 10),

            /*
            | Custom span builder class. Implement Spectra\Contracts\SpanBuilder
            | to control how OpenTelemetry spans are built from request data.
            |
            | The default builder follows OpenTelemetry GenAI semantic conventions.
            | Extend DefaultSpanBuilder to customize span names, attributes, or
            | status — or implement SpanBuilder from scratch for full control.
            |
            | Set to null to use the default span builder.
            */
            'span_builder' => null,

        ],

    ],

];
