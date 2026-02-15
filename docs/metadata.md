# Metadata

Metadata is structured key-value context you attach to tracked AI requests. Unlike tags (which are simple labels), metadata supports richer JSON-style values like strings, numbers, booleans, arrays, and nested objects.

Use metadata to capture request context such as:

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
| High-cardinality diagnostic fields | Not ideal | Yes |

Use both together when needed: tags for quick filtering and metadata for detailed context.

<a name="per-request-metadata"></a>
## Per-Request Metadata

Attach metadata directly inside `Spectra::track()` via the `RequestContext`:

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

Apply metadata to every tracked request in the current process:

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

You can add metadata when creating a streaming tracker:

```php
$tracker = Spectra::stream('openai', 'gpt-4o', [
    'metadata' => [
        'tenant_id' => $tenantId,
        'feature' => 'live-chat',
    ],
]);
```

Spectra also adds `streaming: true` automatically for streamed requests.

<a name="where-metadata-is-stored"></a>
## Where Metadata Is Stored

Metadata is stored as JSON in the `spectra_requests.metadata` column and included in request detail API responses (`GET /api/requests/{id}`).

If you build custom dashboards or exports, metadata is available as structured JSON for downstream analysis.

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
