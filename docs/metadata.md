# Metadata

Metadata is structured key-value context you attach to tracked AI requests. Tags are simple labels. Metadata can hold JSON-style values: strings, numbers, booleans, arrays, and nested objects.

Use metadata for request context such as:

- Tenant or organization IDs
- Feature flags and experiment variants
- Workflow or job identifiers
- Batch processing IDs
- Correlation IDs from external systems

<a name="tags-vs-metadata"></a>
## Tags vs Metadata

| Use Case | Tags | Metadata |
| --- | --- | --- |
| Simple grouping/filtering labels | Best fit | Possible, but overkill |
| Structured context (key-value data) | No | Best fit |
| Multiple fields on a single request | Limited | Yes |
| Fields with many distinct values, such as ids | Not ideal | Yes |

Use both together when needed: tags for quick filtering and metadata for detailed context.

<a name="per-request-metadata"></a>
## Per-Request Metadata

Add metadata inside `Spectra::track()` through the `RequestContext`:

```php
use Spectra\Facades\Spectra;
use OpenAI\Laravel\Facades\OpenAI;

$result = Spectra::track('openai', 'gpt-4o', function ($ctx) use ($tenantId, $jobId) {
    $ctx->addMetadata('tenant_id', $tenantId);
    $ctx->addMetadata('job_id', $jobId);
    $ctx->addMetadata('feature', 'invoice-summary');
    $ctx->addMetadata('is_retry', false);

    return OpenAI::chat()->create([
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'user', 'content' => 'Summarize this invoice.'],
        ],
    ]);
});
```

You can also pass metadata through the `track()` options array:

```php
Spectra::track('openai', 'gpt-4o', fn () => ..., [
    'metadata' => [
        'tenant_id' => $tenantId,
        'workflow' => 'invoice-sync',
        'attempt' => 2,
    ],
]);
```

This is useful when metadata is known before the callback runs.

<a name="global-metadata"></a>
## Global Metadata

Add metadata to every tracked request in the current request or queued job. Global settings are reset between queued jobs and Octane requests:

```php
use Spectra\Facades\Spectra;

Spectra::withMetadata([
    'env' => app()->environment(),
    'region' => 'us-east-1',
    'service' => 'billing-api',
]);
```

Global metadata is merged with per-request metadata. Clear globals when done:

```php
Spectra::clearGlobals();
```

<a name="batch-and-manual-workflows"></a>
## Batch and Manual Workflows

For manual persistence flows (`startRequest()` + `recordSuccess()`), pass metadata in `startRequest()`:

```php
$context = Spectra::startRequest('openai', $model, [
    'pricing_tier' => 'batch',
    'metadata' => [
        'batch_id' => $batchId,
        'custom_id' => $customId,
    ],
]);

Spectra::recordSuccess($context, $response, $usage);
```

This is a common pattern for batch APIs and offline processing pipelines.

<a name="streaming-metadata"></a>
## Streaming Metadata

Streams through Laravel's HTTP client or the OpenAI SDK are tracked automatically, so add metadata the same way as for other requests: with `Spectra::withMetadata()`, or with the `metadata` option of `Http::withAITracking()`:

```php
$response = Http::withAITracking('openai', 'gpt-4o', [
    'metadata' => [
        'tenant_id' => $tenantId,
        'feature' => 'live-chat',
    ],
])
    ->withOptions(['stream' => true])
    ->post('https://api.openai.com/v1/chat/completions', [...]);
```

For SDKs that Spectra does not track on its own, pass metadata when you create a [streaming tracker](/usage#spectra-stream):

```php
$tracker = Spectra::stream('openai', 'gpt-4o', [
    'metadata' => [
        'tenant_id' => $tenantId,
        'feature' => 'live-chat',
    ],
]);
```

Streamed requests are marked with `is_streaming = true`.

<a name="where-metadata-is-stored"></a>
## Where Metadata Is Stored

Metadata is stored as JSON in the `spectra_requests.metadata` column, and it is included in the request detail API response (`GET /api/requests/{id}`).

If you build your own dashboards or exports, you can read the metadata there as structured JSON.

<a name="best-practices"></a>
## Best Practices

- Use stable key names (for example, `tenant_id`, `workflow`, `experiment`).
- Keep metadata compact and JSON-serializable.
- Avoid storing secrets, access tokens, or raw PII in metadata.
- Prefer tags for quick UI filtering and metadata for deeper context.

<a name="testing-metadata"></a>
## Testing Metadata

When using `Spectra::fake()`, assert metadata with:

```php
Spectra::fake();

Spectra::track('openai', 'gpt-4o', function ($ctx) {
    $ctx->addMetadata('tenant_id', 'tenant_123');
    return ['ok' => true];
});

Spectra::assertTrackedWithMetadata([
    'tenant_id' => 'tenant_123',
]);
```
