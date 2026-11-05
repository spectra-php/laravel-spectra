<a href="https://mayahi.net/services" target="_blank" rel="noopener"><img src="/images/hire-me.svg" alt="Hire me: I'm available for consulting and development work" style="width: 100%; margin-bottom: 24px;" /></a>

# Introduction

[Laravel Spectra](https://github.com/spectra-php/laravel-spectra) is an observability package for AI and LLM calls in Laravel applications. It intercepts outgoing requests to AI providers, reads usage metrics such as token counts and media quantities, calculates costs from a built-in pricing catalog, and saves records that feed a built-in real-time dashboard.

Applications use AI APIs for text generation, image generation, embeddings, speech, and more. As usage grows, you need answers to questions like these: which models use the most tokens, how much each feature costs, which users spend the most, and where requests are slow or fail. Spectra answers them in one place, without you instrumenting each call by hand.

<img class="screenshot light-only" src="/images/dashboard/overview.png" alt="The Spectra dashboard" />
<img class="screenshot dark-only" src="/images/dashboard/overview-dark.png" alt="The Spectra dashboard in dark mode" />

See [Dashboard](/dashboard) for a tour of every screen.

<a name="how-it-works"></a>
## How It Works

When your application sends a request to a supported AI provider, Spectra picks it up in one of three ways: automatic tracking, Guzzle middleware, or manual tracking. Spectra then identifies the provider, picks the right handler, reads the usage metrics, calculates the cost from the pricing catalog, and saves the record to the database. Daily statistics are updated in real time, and you can also export traces to OpenTelemetry-compatible backends.

Your application code doesn't see any of this. In most cases you install the package, run the installer, and tracking starts with no code changes.

<a name="supported-providers"></a>
## Supported Providers

For the full list of supported providers, see [Models](/models#supported-providers).

You can add more providers by creating a custom provider class. See [Custom Providers](/custom-providers) for details.

<a name="key-features"></a>
## Key Features

- **Automatic tracking.** Requests made with Laravel's HTTP client and the OpenAI PHP SDK are tracked with no code changes. If you use Guzzle directly, add Spectra's Guzzle middleware.
- **Multimodal support.** Track text completions, embeddings, image generation, video generation, text-to-speech, and speech-to-text across all major providers.
- **Cost calculation.** A built-in pricing catalog with token-based (including prompt-cache reads and writes, reasoning, audio, and image tokens), per-image, per-megapixel, per-video, per-minute, per-second, per-character, per-page, and per-request pricing. Each model can have several pricing tiers (standard, batch, flex, priority, fast, ultrafast), long-context prices and dated prices. Catalogs can also use other currencies.
- **Budget enforcement.** Set cost, token, and request-count limits per user, team, or any Eloquent model. Enforce them with middleware that either blocks requests (hard limit) or only fires warning events (soft limit).
- **Real-time dashboard.** A built-in [dashboard](/dashboard) for exploring requests, traces and conversations, analyzing costs, and viewing per-user analytics.
- **Streaming support.** Streamed responses are tracked automatically as your code reads them, with time-to-first-token metrics and full token counts. This includes streamed image generation through OpenAI's Responses API.
- **Media persistence.** Download generated images and videos to any Laravel filesystem disk before the provider URLs expire.
- **Tags and metadata.** Attach labels and structured metadata to any request, then filter and group by them in the dashboard.
- **User attribution.** Requests are linked to the authenticated user automatically, or you can assign them to any Eloquent model.
- **Flexible persistence.** Save records synchronously, after the HTTP response, or in a queued job, depending on your latency needs.
- **OpenTelemetry integration.** Export AI request traces to any OTLP-compatible backend, so they sit next to the rest of your observability data.
- **Extensible.** Add a custom provider by writing a handler class and registering it in the configuration.
