<?php

declare(strict_types=1);

use Spectra\Pricing\AnthropicPricing;
use Spectra\Pricing\CoherePricing;
use Spectra\Pricing\ElevenLabsPricing;
use Spectra\Pricing\FalAiPricing;
use Spectra\Pricing\GooglePricing;
use Spectra\Pricing\GroqPricing;
use Spectra\Pricing\MistralPricing;
use Spectra\Pricing\OpenAIPricing;
use Spectra\Pricing\OpenRouterPricing;
use Spectra\Pricing\ReplicatePricing;
use Spectra\Pricing\ScalewayPricing;
use Spectra\Pricing\XAiPricing;
use Spectra\Providers\Anthropic\Anthropic;
use Spectra\Providers\Cohere\Cohere;
use Spectra\Providers\ElevenLabs\ElevenLabs;
use Spectra\Providers\FalAi\FalAi;
use Spectra\Providers\Google\Google;
use Spectra\Providers\Groq\Groq;
use Spectra\Providers\Mistral\Mistral;
use Spectra\Providers\Ollama\Ollama;
use Spectra\Providers\OpenAI\OpenAI;
use Spectra\Providers\OpenRouter\OpenRouter;
use Spectra\Providers\Replicate\Replicate;
use Spectra\Providers\Scaleway\Scaleway;
use Spectra\Providers\XAi\XAi;
use Spectra\Support\RequestTransformer;
use Spectra\Watchers\GuzzleWatcher;
use Spectra\Watchers\HttpWatcher;
use Spectra\Watchers\OpenAiWatcher;

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
    | Docs: https://spectraphp.com/configuration#master-switch
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
    | Docs: https://spectraphp.com/configuration#storage
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
        | Docs: https://spectraphp.com/configuration#storage
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
        | Docs: https://spectraphp.com/configuration#storage
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
        | Docs: https://spectraphp.com/configuration#storage
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
        | Docs: https://spectraphp.com/configuration#storage
        |
        */

        'store_prompts' => env('SPECTRA_STORE_PROMPTS', true),

        /*
        |--------------------------------------------------------------------------
        | Store System Prompts
        |--------------------------------------------------------------------------
        |
        | Whether to keep system prompts in the stored request body. System
        | prompts often contain business logic or instructions you'd rather keep
        | private. When disabled, Spectra removes them before saving: system and
        | developer messages, and the system, instructions or systemInstruction
        | fields providers use.
        |
        | Docs: https://spectraphp.com/configuration#storage
        |
        */

        'store_system_prompts' => env('SPECTRA_STORE_SYSTEM_PROMPTS', true),

        /*
        |--------------------------------------------------------------------------
        | Store Tool Definitions
        |--------------------------------------------------------------------------
        |
        | Whether to keep function and tool definitions in the stored request
        | body. Tool schemas can be large and may describe internal APIs. When
        | disabled, Spectra removes the tools (and legacy functions) list before
        | saving. Tool calls in responses are kept.
        |
        | Docs: https://spectraphp.com/configuration#storage
        |
        */

        'store_tools' => env('SPECTRA_STORE_TOOLS', true),

        /*
        |--------------------------------------------------------------------------
        | Store Request Data
        |--------------------------------------------------------------------------
        |
        | Whether to store the raw API request body (the full JSON sent to the
        | provider), which holds the prompts as well as sampling settings and
        | response formats. When unset, it follows store_prompts. Set it to true
        | to keep the body even with store_prompts off; prompts are then stored
        | with it. store_system_prompts and store_tools still apply.
        |
        | Docs: https://spectraphp.com/configuration#storage
        |
        */

        'store_request_data' => env('SPECTRA_STORE_REQUEST_DATA'),

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
        | Docs: https://spectraphp.com/configuration#storage
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
        | Docs: https://spectraphp.com/configuration#media-persistence
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
    | Docs: https://spectraphp.com/configuration#api-keys
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
    | Docs: https://spectraphp.com/configuration#endpoint-urls
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
    | Docs: https://spectraphp.com/configuration#queue
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
        | Docs: https://spectraphp.com/configuration#queue
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
        | Docs: https://spectraphp.com/configuration#queue
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
        | Docs: https://spectraphp.com/configuration#queue
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
        | Docs: https://spectraphp.com/configuration#queue
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
        | Docs: https://spectraphp.com/configuration#persistence-modes
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
    | Docs: https://spectraphp.com/configuration#tracking-context
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
        | Docs: https://spectraphp.com/usage#user-attribution
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
        | Docs: https://spectraphp.com/configuration#tracking-context
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
        | Docs: https://spectraphp.com/configuration#tracking-context
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
        | Docs: https://spectraphp.com/configuration#tracking-context
        |
        */

        'capture_request_id' => true,

        /*
        |--------------------------------------------------------------------------
        | Capture Origin
        |--------------------------------------------------------------------------
        |
        | Whether to store where each AI call came from: the queued job class,
        | the Artisan command, or the route pattern of the HTTP request (for
        | example "POST /chats/{chat}"). Only the pattern is stored, never the
        | URL, so ids and query strings stay out of the database. Traces use
        | it as their name in the dashboard.
        |
        | Docs: https://spectraphp.com/configuration#tracking-context
        |
        */

        'capture_origin' => env('SPECTRA_CAPTURE_ORIGIN', true),

        /*
        |--------------------------------------------------------------------------
        | Root Spans
        |--------------------------------------------------------------------------
        |
        | Whether each HTTP request, queued job and Artisan command that makes AI
        | calls gets a span of its own, named by its origin, with its calls and
        | Spectra::span() blocks nested inside. A queued job's span sits under
        | the span that dispatched it, so a trace reads request, job, call. Only
        | units of work that made at least one AI call are stored.
        |
        | Needs trace_per_request: with it off, each AI call is a trace of its
        | own, so there is no shared trace to hold a root span and none is
        | stored.
        |
        | Docs: https://spectraphp.com/usage#spans
        |
        */

        'root_spans' => env('SPECTRA_ROOT_SPANS', true),

        /*
        |--------------------------------------------------------------------------
        | One Trace Per Request
        |--------------------------------------------------------------------------
        |
        | When enabled, every AI call made during the same HTTP request, queued
        | job, or Artisan command shares one trace id. Queued jobs dispatched
        | during a request keep the trace of that request. The dashboard then
        | shows all calls of a trace as one chain. When disabled, each AI call
        | gets its own trace id unless you set one with Spectra::withTraceId(),
        | and root_spans has no effect.
        |
        | Docs: https://spectraphp.com/usage#traces
        |
        */

        'trace_per_request' => env('SPECTRA_TRACE_PER_REQUEST', true),

        /*
        |--------------------------------------------------------------------------
        | Use Incoming Traceparent
        |--------------------------------------------------------------------------
        |
        | When the incoming HTTP request has a W3C "traceparent" header (sent by
        | an OpenTelemetry-aware proxy, gateway, or caller), Spectra uses its
        | trace id. Spectra's spans then join that distributed trace.
        |
        | Docs: https://spectraphp.com/opentelemetry#trace-correlation
        |
        */

        'use_traceparent' => env('SPECTRA_USE_TRACEPARENT', true),

        /*
        |--------------------------------------------------------------------------
        | Propagate Tags
        |--------------------------------------------------------------------------
        |
        | When enabled, a queued job inherits the tags of the request or job that
        | dispatched it: its global tags and the Spectra::withTags() tags around
        | the dispatch. The job's AI calls then carry them next to their own.
        |
        | Docs: https://spectraphp.com/tags#inherited-tags
        |
        */

        'propagate_tags' => env('SPECTRA_PROPAGATE_TAGS', true),

    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the Spectra dashboard. The dashboard provides a web interface
    | for viewing AI request history, usage statistics, and cost analytics.
    |
    | Docs: https://spectraphp.com/configuration#dashboard
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
        | Docs: https://spectraphp.com/configuration#dashboard
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
        | Docs: https://spectraphp.com/configuration#dashboard
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
        | Docs: https://spectraphp.com/configuration#dashboard
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
        | Docs: https://spectraphp.com/configuration#dashboard
        |
        */

        'middleware' => ['web'],

        /*
        |--------------------------------------------------------------------------
        | Dashboard Maintenance
        |--------------------------------------------------------------------------
        |
        | Whether the dashboard's Maintenance screen may prune old data, rebuild
        | the daily stats and pause recording. Running them also needs the
        | `manageSpectra` gate, which allows only the local environment unless
        | you define it. Set this to false to keep these actions on the
        | command line only; the screen then shows the status read-only.
        |
        | Docs: https://spectraphp.com/dashboard#maintenance
        |
        */

        'maintenance' => env('SPECTRA_DASHBOARD_MAINTENANCE', true),

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
        | Docs: https://spectraphp.com/configuration#dashboard
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
        | Docs: https://spectraphp.com/configuration#dashboard
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
    | Docs: https://spectraphp.com/configuration#costs
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
        | Docs: https://spectraphp.com/configuration#costs
        |
        */

        'enabled' => true,

        /*
        |--------------------------------------------------------------------------
        | Currency
        |--------------------------------------------------------------------------
        |
        | The currency costs are reported and stored in (cents of this
        | currency). Catalog prices written in another currency (Scaleway
        | bills in EUR) and costs that providers report in USD are converted
        | with the exchange rates below.
        |
        | Docs: https://spectraphp.com/costs#currency-conversion
        |
        */

        'currency' => 'USD',

        /*
        |--------------------------------------------------------------------------
        | Exchange Rates
        |--------------------------------------------------------------------------
        |
        | How many units of the reporting currency one unit of another
        | currency is worth, e.g. ['EUR' => 1.17] when reporting in USD, or
        | ['USD' => 0.86] when reporting in EUR. A missing rate counts as 1:1
        | and Spectra logs a warning once per process. Update the rates now
        | and then; Spectra does not fetch them.
        |
        | Docs: https://spectraphp.com/costs#currency-conversion
        |
        */

        'exchange_rates' => [
            // 'EUR' => 1.17,
        ],

        /*
        |--------------------------------------------------------------------------
        | Currency Symbol
        |--------------------------------------------------------------------------
        |
        | The symbol to display before cost values in the dashboard. Common
        | symbols: '$' (USD), '€' (EUR), '£' (GBP), '¥' (JPY/CNY).
        |
        | Docs: https://spectraphp.com/configuration#costs
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
        | `default_tier` is used when neither the request nor the response says
        | which tier served it, and you didn't set one with
        | Spectra::withPricingTier(). Tiers: 'standard' (default), 'batch',
        | 'flex', 'priority' (OpenAI "Fast"), 'fast' (Anthropic fast mode),
        | 'ultrafast', 'hd'. Any provider slug may be listed here.
        |
        | Docs: https://spectraphp.com/costs#configuring-tiers
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
        | Built-in entries you leave out stay active, so new providers reach
        | you without republishing this file. Set an entry to false to turn a
        | built-in pricing class off, e.g. 'groq' => false.
        |
        | Docs: https://spectraphp.com/pricing#customizing-pricing
        |
        */

        'pricing' => [
            'openai' => OpenAIPricing::class,
            'anthropic' => AnthropicPricing::class,
            'google' => GooglePricing::class,
            'xai' => XAiPricing::class,
            'mistral' => MistralPricing::class,
            'openrouter' => OpenRouterPricing::class,
            'replicate' => ReplicatePricing::class,
            'cohere' => CoherePricing::class,
            'groq' => GroqPricing::class,
            'falai' => FalAiPricing::class,
            'scaleway' => ScalewayPricing::class,
            'elevenlabs' => ElevenLabsPricing::class,
        ],

        /*
        |--------------------------------------------------------------------------
        | Scraped Prices
        |--------------------------------------------------------------------------
        |
        | `php artisan spectra:pricing-scrape` reads the providers' pricing
        | pages through Firecrawl and saves the prices that differ from the
        | built-in catalog to this file. Turn `use_scraped_prices` on to bill
        | with them: a model in the file replaces the built-in model with the
        | same id, and every other model keeps its built-in price. Tracking
        | only reads the file; it never calls Firecrawl.
        |
        | Commit the file, so every environment bills the same prices.
        |
        | Docs: https://spectraphp.com/pricing#scraped-prices
        |
        */

        'use_scraped_prices' => (bool) env('SPECTRA_USE_SCRAPED_PRICES', false),

        'scraped_prices_path' => env('SPECTRA_SCRAPED_PRICES_PATH', resource_path('spectra/scraped-prices.json')),

        /*
        |--------------------------------------------------------------------------
        | Firecrawl
        |--------------------------------------------------------------------------
        |
        | `spectra:pricing-scrape` uses Firecrawl's JSON extraction to read
        | the providers' pricing pages. Only the command needs a key; tracking
        | never calls Firecrawl. Timeout is in seconds, per page. The
        | unprefixed FIRECRAWL_API_KEY, FIRECRAWL_API_URL and FIRECRAWL_TIMEOUT
        | variables are still read when the SPECTRA_ ones are not set.
        |
        | `providers` are the ones the command scrapes when you don't name
        | any, e.g. SPECTRA_SCRAPE_PROVIDERS=openai,anthropic. Left empty, it
        | scrapes the providers your app has tracked requests for, so you
        | don't spend Firecrawl credits on providers you don't use.
        |
        | Docs: https://spectraphp.com/pricing#scraping-prices
        |
        */

        'firecrawl' => [
            'api_key' => env('SPECTRA_FIRECRAWL_API_KEY', env('FIRECRAWL_API_KEY')),
            'url' => env('SPECTRA_FIRECRAWL_API_URL', env('FIRECRAWL_API_URL', 'https://api.firecrawl.dev/v2')),
            'timeout' => (int) env('SPECTRA_FIRECRAWL_TIMEOUT', env('FIRECRAWL_TIMEOUT', 180)),
            'providers' => array_values(array_filter(array_map('trim', explode(',', (string) env('SPECTRA_SCRAPE_PROVIDERS', ''))))),
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
    | Docs: https://spectraphp.com/budgets#configuration
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
        | Docs: https://spectraphp.com/budgets#configuration
        |
        */

        'enabled' => env('SPECTRA_BUDGET_ENABLED', true),

        /*
        |--------------------------------------------------------------------------
        | Default Provider
        |--------------------------------------------------------------------------
        |
        | The provider the budget middleware checks when the route gives none
        | (`spectra.budget` without arguments). It matters only for budgets
        | with an allowed-providers list.
        |
        | Docs: https://spectraphp.com/budgets#middleware-enforcement
        |
        */

        'default_provider' => 'openai',

        /*
        |--------------------------------------------------------------------------
        | Default Model
        |--------------------------------------------------------------------------
        |
        | The model the budget middleware checks when the route gives none
        | (`spectra.budget:openai` or no arguments). It matters only for
        | budgets with an allowed-models list.
        |
        | Docs: https://spectraphp.com/budgets#middleware-enforcement
        |
        */

        'default_model' => 'gpt-5.2',

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
    | Docs: https://spectraphp.com/configuration#watcher
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
        | Docs: https://spectraphp.com/configuration#watcher
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
        | Docs: https://spectraphp.com/configuration#watcher
        |
        */

        'watchers' => [
            HttpWatcher::class,
            OpenAiWatcher::class,
            GuzzleWatcher::class,
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
    | Built-in providers you leave out stay active, so new providers reach
    | you without republishing this file. Set an entry to false to stop
    | tracking a built-in provider, e.g. 'ollama' => false.
    |
    | Docs: https://spectraphp.com/configuration#providers
    |
    */

    'providers' => [
        'openai' => ['class' => OpenAI::class, 'name' => 'OpenAI'],
        'anthropic' => ['class' => Anthropic::class, 'name' => 'Anthropic'],
        'google' => ['class' => Google::class, 'name' => 'Google AI'],
        'ollama' => ['class' => Ollama::class, 'name' => 'Ollama'],
        'openrouter' => ['class' => OpenRouter::class, 'name' => 'OpenRouter'],
        'cohere' => ['class' => Cohere::class, 'name' => 'Cohere'],
        'groq' => ['class' => Groq::class, 'name' => 'Groq'],
        'xai' => ['class' => XAi::class, 'name' => 'xAI'],
        'elevenlabs' => ['class' => ElevenLabs::class, 'name' => 'ElevenLabs'],
        'replicate' => ['class' => Replicate::class, 'name' => 'Replicate'],
        'mistral' => ['class' => Mistral::class, 'name' => 'Mistral'],
        'falai' => ['class' => FalAi::class, 'name' => 'fal.ai'],
        'scaleway' => ['class' => Scaleway::class, 'name' => 'Scaleway'],
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
    | Docs: https://spectraphp.com/configuration#integrations
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
        | Docs: https://spectraphp.com/opentelemetry#request-transformer
        |
        */

        'request_transformer' => RequestTransformer::class,

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
        | Docs: https://spectraphp.com/opentelemetry#configuration-options
        |
        */

        'opentelemetry' => [

            /*
            | Enable/disable OpenTelemetry export. When disabled, no traces
            | will be exported even if an endpoint is configured.
            |
            | Docs: https://spectraphp.com/opentelemetry#configuration-options
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
            |
            | Docs: https://spectraphp.com/opentelemetry#configuration-options
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
            |
            | Docs: https://spectraphp.com/opentelemetry#authentication
            */
            'headers' => [],

            /*
            | Service version to include in trace metadata. Helps identify
            | which version of your application generated the trace.
            |
            | Docs: https://spectraphp.com/opentelemetry#configuration-options
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
            |
            | Docs: https://spectraphp.com/opentelemetry#resource-attributes
            */
            'resource_attributes' => [],

            /*
            | Timeout in seconds for OTLP HTTP requests. Default: 10.
            |
            | Docs: https://spectraphp.com/opentelemetry#configuration-options
            */
            'timeout' => (int) env('SPECTRA_OTEL_TIMEOUT', 10),

            /*
            | Custom span builder class. Implement Spectra\Contracts\SpanBuilder
            | to control how OpenTelemetry spans are built from request data.
            |
            | The default builder follows OpenTelemetry GenAI semantic conventions.
            | Extend DefaultSpanBuilder to customize span names, attributes, or
            | status, or implement SpanBuilder from scratch for full control.
            |
            | Set to null to use the default span builder.
            |
            | Docs: https://spectraphp.com/opentelemetry#customizing-spans
            */
            'span_builder' => null,

        ],

    ],

];
