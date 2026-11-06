<a href="https://mayahi.net/services" target="_blank" rel="noopener"><img src="/images/hire-me.svg" alt="Hire me: I'm available for consulting and development work" style="width: 100%; margin-bottom: 24px;" /></a>

# Introduction

[Laravel Spectra](https://github.com/spectra-php/laravel-spectra) records every call your Laravel application makes to an AI provider such as OpenAI, Anthropic or Google, and shows you what happened: which model answered, how many tokens it used, what it cost, how long it took, and whether it failed. It does this by watching the HTTP requests your app already sends, so in most apps there is nothing to change in your code.

That record answers the questions that come up as soon as an app uses AI for real. Which feature costs the most? Which users spend the most? Which model is slow? What failed last night? Is the new prompt better than the old one? Spectra's dashboard answers them in one place.

<img class="screenshot light-only" src="/images/dashboard/overview.png" alt="The Spectra dashboard" />
<img class="screenshot dark-only" src="/images/dashboard/overview-dark.png" alt="The Spectra dashboard in dark mode" />

See [Dashboard](/dashboard) for a tour of every screen.

<a name="how-it-works"></a>
## How It Works

Spectra sits between your app and the provider. When your app sends a request to a provider Spectra knows, Spectra lets it through untouched, reads the response the provider sends back, and saves a record: the provider, the model, the usage the provider reported, the cost worked out from a built-in price list, the latency and the status. Your code gets the same response it would get without Spectra.

Requests made with Laravel's HTTP client or the OpenAI PHP SDK are picked up with no code changes, and so are the libraries built on them, such as Laravel AI and Prism. For a Guzzle client of your own, you add a middleware. For anything else, you wrap the call in `Spectra::track()`. See [Usage](/usage).

Daily totals are updated as records are saved, and the same records can be exported to an [OpenTelemetry](/opentelemetry) backend, so your AI calls sit next to the rest of your monitoring.

<a name="concepts"></a>
## The Words Spectra Uses {#concepts}

The rest of these docs use a handful of words from AI observability. Each has its own page; this is what they mean.

| Word | What it is |
| --- | --- |
| **Request** or **call** | One HTTP request to an AI provider: a chat completion, an embedding, an image, a transcription. Everything else is built on these. See [Usage](/usage). |
| **Trace** | All the AI calls made for one piece of work: one HTTP request to your app, one queued job or one Artisan command. A support question that searches your documents, drafts a reply and checks it is one trace with several calls. See [Traces](/usage#traces). |
| **Span** | One timed step inside a trace: a block of your code, a tool the model asked for, or the request or job itself. Spans nest, so a trace reads as a tree. See [Spans](/usage#spans). |
| **Conversation** | A chat with many back-and-forth messages. Each **turn** is one user message and the calls made to answer it. See [Conversations](/usage#conversation-tracking). |
| **Tags** and **metadata** | Labels and key-value context you attach to calls, to filter and group them by feature, tenant or experiment. See [Tags](/tags) and [Metadata](/metadata). |
| **Cost** | What a call cost, worked out from the usage the provider reported and a built-in price list. See [Costs](/costs) and [Pricing](/pricing). |
| **Budget** | A spending or usage limit for one user, team or any other model, checked before a call runs. See [Budgets](/budgets). |
| **Alert** | A rule over your whole traffic, such as "more than 10% of calls failed in the last 15 minutes", that notifies you when it is broken. See [Alerts](/alerts). |
| **Score** | A grade on an answer: a user's thumbs up, a model's rating, a reviewer's label. Cost says what you paid; a score says whether it was worth it. See [Scores](/scores). |
| **Prompt version** | The name and version of the prompt template a call used, so the dashboard can compare versions. See [Prompts](/prompts). |
| **Tool** | A function the model asked your code to run, such as looking up an order, drawn as a step in the trace. See [Tools](/tools). |
| **Annotation queue** | A review queue: traces waiting for a person on your team to read and grade. See [Annotation Queues](/annotations). |
| **Dataset** | A named list of test cases, each with an input and the answer you expect, kept so you can ask your AI code the same questions after every change. See [Datasets](/datasets). |
| **Evaluation** | A test that runs your AI code over a fixed set of examples and grades every answer, so you can compare before and after a change. See [Evaluations](/evaluations). |
| **Playground** | A dashboard screen where you try a new prompt, or open a real call, change its prompt or model, and send it again to compare the answers. Each try is a **run**. See [Playground](/playground). |
| **Dashboards** | Read-only pages you design and share with someone outside Spectra, such as a customer who wants to see their own usage. See [Dashboards](/dashboards). |

<a name="supported-providers"></a>
## Supported Providers

For the full list of supported providers, see [Models](/models#supported-providers).

You can add more providers by creating a custom provider class. See [Custom Providers](/custom-providers) for details.

<a name="key-features"></a>
## Key Features

- **Automatic tracking.** Requests made with Laravel's HTTP client and the OpenAI PHP SDK are tracked with no code changes. If you use Guzzle directly, add Spectra's Guzzle middleware.
- **Every kind of model.** Text, embeddings, image generation, video generation, text-to-speech and speech-to-text, across all major providers.
- **Cost calculation.** A built-in price list that knows how each model bills: per token (with separate prices for cached prompts, reasoning, audio and images), per image, per megapixel, per video, per minute, per second, per character, per page or per request. Models can have several pricing tiers (standard, batch, flex, priority, fast, ultrafast), long-context prices and dated prices. Catalogs can also use other currencies.
- **Budgets.** Set cost, token and request-count limits per user, team or any Eloquent model. Enforce them with middleware that blocks requests (hard limit) or only fires warning events (soft limit).
- **Alerts.** Rules over error rate, latency, spend and volume, checked on a schedule and sent by email, Slack or webhook.
- **Quality, not only cost.** Scores from users, models and reviewers, prompt versions compared side by side, review queues for your team, and evaluations that grade your AI code over a dataset.
- **Real-time dashboard.** A built-in [dashboard](/dashboard) for exploring requests, traces and conversations, analyzing costs, and viewing per-user analytics.
- **Streaming support.** Streamed responses are tracked as your code reads them, with the time to the first token and full token counts. This includes streamed image generation through OpenAI's Responses API.
- **Media persistence.** Download generated images and videos to any Laravel filesystem disk before the provider URLs expire.
- **Tags and metadata.** Attach labels and structured metadata to any request, then filter and group by them in the dashboard.
- **User attribution.** Requests are linked to the authenticated user automatically, or you can assign them to any Eloquent model.
- **Saving records your way.** Save them during the request, after the response is sent, or in a queued job, depending on your latency needs.
- **OpenTelemetry integration.** Export AI request traces to any OpenTelemetry backend, so they sit next to the rest of your monitoring.
- **Extensible.** Add a custom provider by writing a handler class and registering it in the configuration.
