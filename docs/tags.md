# Tags

Tags provide flexible, user-defined categorization for tracked AI requests. They are simple string labels that you attach to requests for filtering, grouping, and analysis in the dashboard. Tags are useful for organizing requests by feature area, environment, priority level, experiment name, customer tier, or any other dimension that matters to your application.

Unlike provider and model data, which Spectra extracts automatically, tags are entirely under your control. You decide what to tag, when to tag it, and how to use the tags for filtering and reporting.

If you need structured key-value context instead of simple labels, use [Metadata](/metadata).

<a name="attaching-tags"></a>
## Attaching Tags

Tags are attached via the `RequestContext` when using manual tracking with `Spectra::track()`:

```php
use Spectra\Facades\Spectra;

$result = Spectra::track('openai', 'gpt-4o', function ($ctx) {
    $ctx->addTag('chat');
    $ctx->addTag('high-priority');
    $ctx->addTag('prompt-v2');

    return OpenAI::chat()->create([
        'model' => 'gpt-4o',
        'messages' => $messages,
    ]);
});
```

You can also pass tags as an option to the `track()` method:

```php
Spectra::track('openai', 'gpt-4o', fn () => ..., [
    'tags' => ['chat', 'high-priority'],
]);
```

<a name="global-tags"></a>
## Global Tags

Global tags are applied to **every** tracked request in the current process. This is useful for attaching environment-level context without explicitly tagging each individual request:

```php
// In a middleware or service provider
Spectra::addGlobalTags(['production', 'us-east-1', 'content-api']);

// Every subsequent tracked request in this process will include these tags
```

Global tags are merged with any request-specific tags. Clear global tags at the end of a middleware or process with:

```php
Spectra::clearGlobals();
```

<a name="filtering-by-tag"></a>
## Filtering by Tag

Tags are filterable in the Spectra dashboard's request explorer. You can filter by specific tags to narrow down requests to a particular feature, experiment, or environment. Tags are also available through the API at the `/api/tags` endpoint, which returns all known tag names for use in filter UI components.

<a name="storage-structure"></a>
## Storage Structure

Tags are stored in a normalized, many-to-many structure for efficient querying:

- **`spectra_tags`** — A lookup table of unique tag names (for example, `chat`, `production`). Each unique tag string is stored once.
- **`spectra_requests_tags`** — A pivot table linking requests to tags. Deleting a request cascades to its pivot entries.

This normalized design avoids storing duplicate tag strings across millions of request records and enables efficient tag-based queries and aggregations.
