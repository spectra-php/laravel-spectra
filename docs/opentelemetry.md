# OpenTelemetry

Spectra can export AI request data to external observability platforms via the OpenTelemetry Protocol (OTLP), giving you visibility into AI operations alongside your existing monitoring infrastructure. Each tracked AI request becomes a span in your distributed tracing system, complete with provider, model, token usage, cost, latency, and status metadata.

<a name="what-is-opentelemetry"></a>
## What is OpenTelemetry?

[OpenTelemetry](https://opentelemetry.io/) (often abbreviated as OTel) is an open-source, vendor-neutral observability framework maintained by the Cloud Native Computing Foundation (CNCF). It defines a standard format for telemetry data — traces, metrics, and logs — that is supported by virtually every major observability platform.

In practical terms, a **trace** represents the full journey of a request through your system. Each step in that journey is a **span**. Spectra creates a span for every tracked AI request, enriched with structured attributes including the provider name, model identifier, token counts, calculated cost, latency, and HTTP status code.

The key benefit of OpenTelemetry is portability. You export your traces in OTLP format once, and you can send them to any compatible backend — Jaeger, Grafana Tempo, Datadog, New Relic, Honeycomb, or dozens of others. If you later switch observability vendors, you change the endpoint configuration, not your application code.

<a name="when-to-use-opentelemetry"></a>
## When to Use OpenTelemetry

The OpenTelemetry integration is valuable when you already use an observability platform and want AI request data in the same place as the rest of your telemetry. Specific scenarios include:

- Correlating AI calls with other services in a distributed system to understand end-to-end request flow.
- Setting up alerts on AI request latency, error rates, or cost thresholds through your existing alerting infrastructure.
- Building unified dashboards that combine AI metrics with application performance metrics.
- Meeting enterprise monitoring requirements that mandate centralized observability with retention policies and access controls.

If you only need AI observability and don't have an existing tracing infrastructure, the built-in Spectra dashboard may be sufficient on its own.

<a name="setup"></a>
## Setup

Enable the integration in `config/spectra.php` and provide the OTLP endpoint for your collector or backend:

```php
'integrations' => [
    'opentelemetry' => [
        'enabled' => env('SPECTRA_OTEL_ENABLED', true),
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

Spectra exports traces in standard OTLP HTTP format, which is supported by all major observability platforms:

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

Resource attributes are key-value pairs added to every exported trace. They describe the environment in which the trace was generated — deployment region, Kubernetes namespace, service tier, and similar infrastructure metadata. Use them to filter and group traces in your observability backend:

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

Spectra assigns a `trace_id` to each tracked request. This identifier appears in both the Spectra dashboard and the exported OpenTelemetry spans, allowing you to follow a single user action across multiple AI calls and external services.

### Automatic Trace IDs

Every tracked request receives a UUID trace ID automatically. Requests made within the same `Spectra::track()` callback or the same HTTP request share the same trace ID by default, making it easy to correlate related operations.

### Custom Trace IDs

You can provide your own trace ID to integrate with an existing tracing system. This is useful when you want AI request spans to appear under the same trace as your application's HTTP request or background job:

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
| Spectra Dashboard | Filterable in the request explorer's "Trace ID" field |
| OpenTelemetry | The span's `trace_id` attribute, visible in your backend's trace view |

This enables end-to-end tracing: a user clicking "Generate Summary" triggers an API route, which calls an AI model, which writes to the database, which dispatches a queue job — all linked under a single trace ID visible across both Spectra and your observability platform.

<a name="customizing-spans"></a>
## Customizing Spans

The default span builder follows the [OpenTelemetry GenAI semantic conventions](https://opentelemetry.io/docs/specs/semconv/gen-ai/), exporting attributes like `gen_ai.system`, `gen_ai.request.model`, token counts, cost, and latency. You can customize how spans are built by providing your own `SpanBuilder` implementation.

### Extending the Default Builder

The easiest approach is to extend `DefaultSpanBuilder` and override the specific methods you want to change. All methods are `protected` and designed for selective overriding:

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

The `$data` array passed to `SpanBuilder::build()` contains these fields:

| Field | Type | Description |
| --- | --- | --- |
| `id` | `string` | Unique request ID |
| `trace_id` | `?string` | Trace ID for correlation |
| `response_id` | `?string` | Provider's response ID |
| `provider` | `string` | Provider name (e.g. `openai`) |
| `model` | `string` | Model name (e.g. `gpt-4o`) |
| `snapshot` | `?string` | Model version returned by the API |
| `model_type` | `?string` | Classification: `text`, `image`, `tts`, `stt`, `video` |
| `endpoint` | `?string` | API endpoint path |
| `pricing_tier` | `?string` | Pricing tier used |
| `prompt_tokens` | `int` | Input tokens |
| `completion_tokens` | `int` | Output tokens |
| `reasoning_tokens` | `int` | Reasoning/thinking tokens |
| `total_tokens` | `int` | Sum of prompt + completion tokens |
| `total_cost_in_cents` | `float` | Total cost in cents |
| `latency_ms` | `?int` | Request latency in milliseconds |
| `status_code` | `?int` | HTTP status code |
| `is_failed` | `bool` | Whether the request failed |
| `finish_reason` | `?string` | Model's finish reason |
| `is_reasoning` | `bool` | Whether reasoning was used |
| `is_streaming` | `bool` | Whether the request was streamed |
| `has_tool_calls` | `bool` | Whether tools were called |
| `started_at` | `Carbon` | Request start time |
| `completed_at` | `Carbon` | Request completion time |
| `metadata` | `?array` | Custom metadata |

<a name="request-transformer"></a>
## Request Transformer

The `RequestTransformer` class controls how `SpectraRequest` models are converted into the data arrays that integrations and events receive. The default transformer extracts all relevant fields. You can extend it to add, remove, or modify fields:

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

Spectra dispatches a `RequestTracked` event after every AI request is persisted. The event carries the same data array produced by the `RequestTransformer`, making it easy to build custom integrations without modifying Spectra internals.

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
