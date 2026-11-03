# OpenTelemetry

Spectra can export AI request data to external observability platforms over the OpenTelemetry Protocol (OTLP), so you see your AI calls next to the rest of your monitoring. Each tracked AI request becomes a span in your distributed tracing system, with its provider, model, token usage, cost, latency, and status.

<a name="what-is-opentelemetry"></a>
## What is OpenTelemetry?

[OpenTelemetry](https://opentelemetry.io/) (often abbreviated as OTel) is an open-source, vendor-neutral observability framework maintained by the Cloud Native Computing Foundation (CNCF). It defines a standard format for telemetry data (traces, metrics, and logs), and virtually every major observability platform supports it.

In practice, a **trace** is the full journey of a request through your system. Each step in that journey is a **span**. Spectra creates a span for every tracked AI request, with attributes for the provider name, model identifier, token counts, calculated cost, latency, and HTTP status code.

The main benefit of OpenTelemetry is portability. You export your traces in OTLP format once, and you can send them to any compatible backend: Jaeger, Grafana Tempo, Datadog, New Relic, Honeycomb, or dozens of others. If you switch observability vendors later, you change the endpoint configuration, not your application code.

<a name="when-to-use-opentelemetry"></a>
## When to Use OpenTelemetry

The OpenTelemetry integration is most useful when you already use an observability platform and want AI request data in the same place as the rest of your telemetry. Typical uses:

- Correlating AI calls with other services in a distributed system to understand end-to-end request flow.
- Setting up alerts on AI request latency, error rates, or cost thresholds through your existing alerting infrastructure.
- Building unified dashboards that combine AI metrics with application performance metrics.
- Meeting enterprise monitoring requirements that mandate centralized observability with retention policies and access controls.

If you only need AI observability and don't already have tracing infrastructure, the built-in Spectra dashboard may be all you need.

<a name="setup"></a>
## Setup

Enable the integration in `config/spectra.php` and provide the OTLP endpoint for your collector or backend:

```php
'integrations' => [
    'opentelemetry' => [
        'enabled' => env('SPECTRA_OTEL_ENABLED', false),
        'endpoint' => env('SPECTRA_OTEL_ENDPOINT', 'http://localhost:4318/v1/traces'),
        'headers' => [],
        'service_version' => env('SPECTRA_OTEL_SERVICE_VERSION', '1.0.0'),
        'resource_attributes' => [],
        'timeout' => env('SPECTRA_OTEL_TIMEOUT', 10),
    ],
],
```

Then set the environment variables:

```bash
SPECTRA_OTEL_ENABLED=true
SPECTRA_OTEL_ENDPOINT=http://localhost:4318/v1/traces
```

<a name="configuration-options"></a>
## Configuration Options

| Option | Default | Description |
| --- | --- | --- |
| `enabled` | `false` | Whether to export traces to the configured OTLP endpoint. |
| `endpoint` | `http://localhost:4318/v1/traces` | The OTLP HTTP endpoint for your collector or observability backend. |
| `headers` | `[]` | Custom HTTP headers sent with each export request. Typically used for authentication tokens or API keys. |
| `service_version` | `1.0.0` | A version string included in trace metadata. Useful for identifying which deployment generated a given trace. |
| `resource_attributes` | `[]` | Key-value pairs added to every exported trace. Used for deployment region, Kubernetes namespace, service tier, or other infrastructure context. |
| `timeout` | `10` | HTTP timeout in seconds for OTLP export requests. Increase this if your backend is remote or slow to respond. |

<a name="compatible-backends"></a>
## Compatible Backends

Spectra exports traces in standard OTLP HTTP format, which all major observability platforms support:

| Backend | Type | Endpoint Example |
| --- | --- | --- |
| Jaeger | Open source | `http://localhost:4318/v1/traces` |
| Zipkin | Open source | `http://localhost:9411/api/v2/spans` |
| Grafana Tempo | Open source | `http://tempo:4318/v1/traces` |
| Datadog APM | Cloud | `https://trace.agent.datadoghq.com/v1/traces` |
| New Relic | Cloud | `https://otlp.nr-data.net:4318/v1/traces` |
| AWS X-Ray | Cloud | Via OpenTelemetry Collector |
| Google Cloud Trace | Cloud | Via OpenTelemetry Collector |
| Honeycomb | Cloud | `https://api.honeycomb.io/v1/traces` |
| Lightstep | Cloud | `https://ingest.lightstep.com:443/traces/otlp/v0.9` |

> [!TIP]
> The easiest way to test the OpenTelemetry integration locally is with Jaeger. Start a Jaeger instance with Docker:
>
> ```shell
> docker run -d --name jaeger \
>   -p 16686:16686 \
>   -p 4318:4318 \
>   jaegertracing/all-in-one:latest
> ```
>
> Set `SPECTRA_OTEL_ENDPOINT=http://localhost:4318/v1/traces` and open `http://localhost:16686` to view traces in the Jaeger UI.

<a name="authentication"></a>
## Authentication

Most cloud backends require authentication headers. Add them to the `headers` array in the configuration. The exact header depends on your provider:

```php
'opentelemetry' => [
    'headers' => [
        // Bearer token (New Relic, Honeycomb, etc.)
        'Authorization' => 'Bearer ' . env('OTEL_AUTH_TOKEN'),

        // API key (Datadog, etc.)
        'x-api-key' => env('OTEL_API_KEY'),

        // Honeycomb-specific team key
        'x-honeycomb-team' => env('HONEYCOMB_API_KEY'),
    ],
],
```

<a name="resource-attributes"></a>
## Resource Attributes

Resource attributes are key-value pairs added to every exported trace. They describe the environment the trace came from, such as the deployment region, Kubernetes namespace, service tier, and similar infrastructure metadata. Use them to filter and group traces in your observability backend:

```php
'opentelemetry' => [
    'resource_attributes' => [
        'deployment.environment' => 'production',
        'deployment.region' => 'us-east-1',
        'k8s.namespace' => 'ai-services',
        'service.team' => 'platform',
    ],
],
```

<a name="export-timing"></a>
## Export Timing

OpenTelemetry export follows the same persistence mode as request storage, controlled by the [queue configuration](/configuration#queue):

| Queue Config | Export Behavior |
| --- | --- |
| `queue.enabled: true` | Dispatched as an `ExportTrackedRequestJob` on your configured queue |
| `queue.after_response: true` | Exported after the HTTP response is sent to the client (no added latency for the user) |
| Both `false` (default) | Exported synchronously after the AI response completes |

> [!NOTE]
> In non-HTTP contexts such as console commands and queue workers, `after_response` has no effect. Traces are always exported synchronously in those contexts unless queue mode is enabled.

<a name="trace-correlation"></a>
## Trace Correlation

Spectra assigns a `trace_id` to each tracked request. The same ID appears in the Spectra dashboard and in the exported OpenTelemetry spans, so you can follow a single user action across multiple AI calls and external services.

### Automatic Trace IDs

All AI calls of the same HTTP request, queued job, or Artisan command share one trace ID. A queued job dispatched during a request keeps the request's trace ID. Each exported span is a separate span inside that trace, so your backend shows the calls of one request together. See [Traces](/usage#traces).

### Joining an Incoming Trace

When the incoming HTTP request has a W3C [`traceparent`](https://www.w3.org/TR/trace-context/#traceparent-header) header, Spectra uses its trace ID and sets the caller's span as the parent of each AI span. Your gateway, proxy, or calling service then shows the AI calls nested under its own span.

The header's 32-character trace ID is stored as a UUID in the `trace_id` column (the same 128 bits), and the parent span ID in the `parent_span_id` column. The exported span uses the original hex value, so it matches the caller's trace.

Turn this off with `SPECTRA_USE_TRACEPARENT=false`.

### Custom Trace IDs

You can provide your own trace ID to integrate with an existing tracing system. Use a UUID, or a 32-character hex OpenTelemetry trace ID written as a UUID. Set it for the rest of the request or job with `Spectra::withTraceId($traceId)`, or for one call:

```php
$result = Spectra::track('openai', 'gpt-4o', function ($ctx) use ($myTraceId) {
    return OpenAI::chat()->create([
        'model' => 'gpt-4o',
        'messages' => $messages,
    ]);
}, ['trace_id' => $myTraceId]);
```

### Where Trace IDs Appear

| System | Location |
| --- | --- |
| Spectra Dashboard | The **Trace** card on the request page, and the requests list filter |
| OpenTelemetry | The span's `trace_id` attribute, visible in your backend's trace view |

This gives you end-to-end tracing. A user clicks "Generate Summary", which triggers an API route that calls an AI model, writes to the database, and dispatches a queue job. All of these steps share one trace ID, which you can see in both Spectra and your observability platform.

<a name="span-attributes"></a>
## Span Name and Attributes

The default span builder follows the current OpenTelemetry GenAI semantic conventions for client inference spans. You can read them in the [semantic conventions repository](https://github.com/open-telemetry/semantic-conventions-genai).

The span name is `{operation} {model}`, for example `chat gpt-4o`. The operation (`gen_ai.operation.name`) is one of `chat`, `embeddings`, `generate_content`, `text_completion`, `rerank`, or `transcription`. The model is the provider's model id (the `snapshot` column). Spectra stores it for every request it can name, even when the response echoes the model you asked for.

Every span has these attributes:

| Attribute | Value |
| --- | --- |
| `gen_ai.operation.name` | The operation, as above |
| `gen_ai.provider.name` | The provider (`openai`, `anthropic`, ...; `gcp.gemini` for Google, `x_ai` for xAI) |
| `gen_ai.request.model` | The model, as in the span name |
| `gen_ai.usage.input_tokens` | Prompt tokens (the full prompt, including cache reads and writes) |
| `gen_ai.usage.output_tokens` | Completion tokens (the full output, including reasoning) |
| `http.response.status_code` | The HTTP status code |
| `spectra.trace_id`, `spectra.request_id` | Spectra's trace id and request id |
| `spectra.model` | The model name Spectra stored |
| `spectra.cost_cents` | The total cost, in cents |
| `spectra.latency_ms` | The latency, in milliseconds |

These attributes are added when they have a value:

| Attribute | Value |
| --- | --- |
| `gen_ai.response.model` | The model id the provider returned |
| `gen_ai.response.id` | The provider's response id |
| `gen_ai.response.finish_reasons` | The finish reason, as an array |
| `gen_ai.usage.cache_read.input_tokens` | Prompt tokens read from the cache |
| `gen_ai.usage.cache_write.input_tokens` | Prompt tokens written to the cache |
| `gen_ai.usage.reasoning.output_tokens` | Reasoning tokens |
| `gen_ai.request.stream` | `true` for streamed requests |
| `gen_ai.conversation.id` | The conversation id |
| `error.type` | The error class (or the status code) of a failed request |
| `spectra.endpoint` | The API endpoint path |
| `spectra.model_type`, `spectra.pricing_tier`, `spectra.currency` | Model type, pricing tier, and the currency of the cost |
| `spectra.trackable.type`, `spectra.trackable.id` | The trackable (user, team, ...) |
| `spectra.reasoning_effort`, `spectra.has_tool_calls` | Reasoning effort and tool use |
| `spectra.time_to_first_token_ms`, `spectra.tokens_per_second`, `spectra.duration_seconds` | Streaming and media metrics |

A failed request has the error status, with the provider's error message.

<a name="attribute-changes"></a>
### Changes from 1.x

Spectra 2.0 renamed several attributes to follow the current conventions:

| 1.x | 2.0 |
| --- | --- |
| span name `ai.{provider}.{model}` | `{operation} {model}`, e.g. `chat gpt-4o` |
| `gen_ai.system` | `gen_ai.provider.name` (`gcp.gemini` for Google, `x_ai` for xAI) |
| `gen_ai.operation.name` = endpoint path | `gen_ai.operation.name` = `chat`, `embeddings`, `generate_content`, ...; the path moved to `spectra.endpoint` |
| `gen_ai.usage.prompt_tokens` | `gen_ai.usage.input_tokens` |
| `gen_ai.usage.completion_tokens` | `gen_ai.usage.output_tokens` |
| `gen_ai.usage.total_tokens` | removed (input + output) |
| `gen_ai.usage.reasoning_tokens` | `gen_ai.usage.reasoning.output_tokens` |
| `gen_ai.response.finish_reason` | `gen_ai.response.finish_reasons` (array) |
| `spectra.is_streaming` | `gen_ai.request.stream` |
| None (new in 2.0) | `gen_ai.usage.cache_read.input_tokens`, `gen_ai.usage.cache_write.input_tokens`, `gen_ai.conversation.id`, `error.type`, `spectra.currency`, `spectra.model` |

If your dashboards or alerts use the old names, update them. Or extend `DefaultSpanBuilder` and override `attributes()` or `spanName()` to keep the old names (see below).

<a name="customizing-spans"></a>
## Customizing Spans

You can customize how spans are built by providing your own `SpanBuilder` implementation.

### Extending the Default Builder

The easiest approach is to extend `DefaultSpanBuilder` and override only the methods you want to change. Apart from `build()`, its methods are `protected`, so you can override them one at a time:

```php
use Spectra\Integrations\OpenTelemetry\DefaultSpanBuilder;

class MySpanBuilder extends DefaultSpanBuilder
{
    protected function spanName(array $data): string
    {
        return "myapp.ai.{$data['provider']}.{$data['model']}";
    }

    protected function attributes(array $data): array
    {
        $attrs = parent::attributes($data);

        // Add custom attributes
        $attrs[] = ['key' => 'myapp.team', 'value' => ['stringValue' => 'ml-platform']];

        // Remove attributes you don't want exported
        $attrs = array_filter($attrs, fn ($a) => $a['key'] !== 'spectra.cost_cents');

        return $attrs;
    }
}
```

Register it in `config/spectra.php`:

```php
'opentelemetry' => [
    'span_builder' => \App\Telemetry\MySpanBuilder::class,
],
```

### Building Spans from Scratch

For full control, implement the `SpanBuilder` contract directly. The `build()` method receives a data array (produced by `RequestTransformer`) and must return an OTLP-compatible span array:

```php
use Spectra\Contracts\SpanBuilder;

class MinimalSpanBuilder implements SpanBuilder
{
    public function build(array $data): array
    {
        return [
            'traceId' => str_replace('-', '', $data['trace_id'] ?? ''),
            'spanId' => bin2hex(random_bytes(8)),
            'parentSpanId' => '',
            'name' => "llm.{$data['model']}",
            'kind' => 3,
            'startTimeUnixNano' => (string) ((int) $data['started_at']->getPreciseTimestamp(6) * 1000),
            'endTimeUnixNano' => (string) ((int) $data['completed_at']->getPreciseTimestamp(6) * 1000),
            'attributes' => [
                ['key' => 'model', 'value' => ['stringValue' => $data['model']]],
                ['key' => 'tokens', 'value' => ['intValue' => $data['total_tokens']]],
                ['key' => 'cost_cents', 'value' => ['doubleValue' => $data['total_cost_in_cents']]],
            ],
            'status' => ['code' => $data['is_failed'] ? 2 : 1],
            'events' => [],
            'links' => [],
        ];
    }
}
```

### Available Data

The `$data` array passed to `SpanBuilder::build()` holds the stored request columns (without the raw `request` and `response` payloads). The main fields are:

| Field | Type | Description |
| --- | --- | --- |
| `id` | `string` | Unique request ID |
| `trace_id` | `?string` | Trace ID for correlation |
| `response_id` | `?string` | Provider's response ID |
| `conversation_id` | `?string` | Conversation ID, if set |
| `conversation_turn` | `?int` | Turn number in the conversation |
| `provider` | `string` | Provider name (e.g. `openai`) |
| `model` | `string` | The catalog display name of the requested model (e.g. `GPT-4o`), or its id when the catalog doesn't know it |
| `snapshot` | `?string` | The provider's model id: the one the response returned, or the requested id when the response didn't name one |
| `model_type` | `?string` | Classification: `text`, `embedding`, `image`, `tts`, `stt`, `video` |
| `endpoint` | `?string` | API endpoint path |
| `pricing_tier` | `?string` | Pricing tier used |
| `prompt_tokens` | `int` | Input tokens (the full prompt) |
| `completion_tokens` | `int` | Output tokens (the full output) |
| `cached_tokens` | `int` | Prompt tokens read from the cache |
| `cache_write_tokens` | `int` | Prompt tokens written to the cache |
| `reasoning_tokens` | `int` | Reasoning/thinking tokens |
| `total_tokens` | `int` | Sum of prompt + completion tokens |
| `prompt_cost` | `float` | Prompt cost in cents |
| `completion_cost` | `float` | Completion cost in cents |
| `total_cost_in_cents` | `float` | Total cost in cents |
| `currency` | `?string` | The currency of the costs (e.g. `USD`) |
| `latency_ms` | `?int` | Request latency in milliseconds |
| `time_to_first_token_ms` | `?int` | Time to the first token (streaming) |
| `status_code` | `?int` | HTTP status code |
| `is_failed` | `bool` | Whether the request failed |
| `error_type` | `?string` | Error class of a failed request |
| `error_message` | `?string` | The provider's error message |
| `finish_reason` | `?string` | Model's finish reason |
| `is_reasoning` | `bool` | Whether reasoning was used |
| `is_streaming` | `bool` | Whether the request was streamed |
| `has_tool_calls` | `bool` | Whether tools were called |
| `tool_call_counts` | `?array` | Tool calls by type |
| `trackable_type` | `?string` | Trackable morph type |
| `trackable_id` | `?string` | Trackable id |
| `request_source` | `?string` | `web`, `api`, or `console` |
| `started_at` | `Carbon` | Request start time |
| `completed_at` | `Carbon` | Request completion time |
| `metadata` | `?array` | Custom metadata |

<a name="request-transformer"></a>
## Request Transformer

The `RequestTransformer` class controls how `SpectraRequest` models are converted into the data arrays that integrations and events receive. The default transformer extracts all relevant fields. Extend it to add, remove, or change fields:

```php
use Spectra\Support\RequestTransformer;
use Spectra\Models\SpectraRequest;

class MyTransformer extends RequestTransformer
{
    public function transform(SpectraRequest $request): array
    {
        $data = parent::transform($request);

        // Add custom fields
        $data['environment'] = config('app.env');
        $data['team'] = 'ml-platform';

        // Remove sensitive fields
        unset($data['trackable_type'], $data['trackable_id']);

        return $data;
    }
}
```

Register it in `config/spectra.php`:

```php
'integrations' => [
    'request_transformer' => \App\Telemetry\MyTransformer::class,
],
```

<a name="events"></a>
## Events

Spectra dispatches a `RequestTracked` event after every AI request is persisted. The event carries the same data array the `RequestTransformer` produces, so you can build your own integrations without changing Spectra itself.

### Listening to the Event

Register a listener in your `EventServiceProvider` or use a closure:

```php
use Spectra\Events\RequestTracked;

// In EventServiceProvider
protected $listen = [
    RequestTracked::class => [
        \App\Listeners\SendToDatadog::class,
        \App\Listeners\AlertOnHighCost::class,
    ],
];
```

### Listener Example

```php
use Spectra\Events\RequestTracked;

class AlertOnHighCost
{
    public function handle(RequestTracked $event): void
    {
        $data = $event->request;

        if ($data['total_cost_in_cents'] > 100) {
            // Send a Slack alert, log, or trigger a notification
            logger()->warning('High-cost AI request', [
                'model' => $data['model'],
                'cost_cents' => $data['total_cost_in_cents'],
                'tokens' => $data['total_tokens'],
            ]);
        }
    }
}
```

### Available Event Data

The `$event->request` array contains the same fields listed in the [Available Data](#customizing-spans) table above. The data is produced by the configured `RequestTransformer`.
