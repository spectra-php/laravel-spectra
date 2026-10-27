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

<a name="scoped-tags"></a>
## Scoped Tags

Spectra tracks most requests by itself, without `track()`. To tag those requests, wrap the code that sends them in `Spectra::withTags()`. The tags apply only to the requests sent inside the callback:

```php
use Spectra\Facades\Spectra;

$summary = Spectra::withTags(['summary'], fn () => Http::withToken($key)
    ->post('https://api.openai.com/v1/chat/completions', $payload)
    ->json());
```

`withTags()` returns what the callback returns. Calls can nest: an inner call adds its tags to the outer ones, and the outer tags come back when it ends, even if the callback throws. A streamed response keeps the tags it was sent with, even when it is read after the callback ends.

This lets one request or job tag each of its AI calls on its own. For example, a chat request can tag the follow-up rewrite, the search and the reply with different tags. Global tags would put the same tags on all of them.

<a name="global-tags"></a>
## Global Tags

Global tags are applied to **every** tracked request in the current request or queued job. They are reset between queued jobs and Octane requests. This is useful for attaching environment-level context without explicitly tagging each individual request:

```php
// In a middleware or a queued job
Spectra::addGlobalTags(['production', 'us-east-1', 'content-api']);

// Every later tracked request in this request or job will include these tags
```

Global tags are merged with scoped and request-specific tags. Clear global tags earlier, for example in a long-running command, with:

```php
Spectra::clearGlobals();
```

<a name="inherited-tags"></a>
## Inherited Tags

Tags carry on like the [trace](/usage#traces):

- **Queued jobs.** A job inherits the tags its AI calls would get where it is dispatched: the global tags, and the `withTags()` tags around the dispatch. A job that dispatches another job hands them on. The job's own tags are added to the inherited ones.
- **Your own services.** `Spectra::propagationHeaders()` sends the current tags (and the trace) to another of your services, where the `spectra.trace` middleware picks them up. See [Your own services join the trace](/usage#traces).

```php
// A chat reply asks for a video. The job's fal.ai calls are tagged
// `chat-reply` as well as their own `video-generation`.
Spectra::withTags(['chat-reply'], fn () => GenerateVideoJob::dispatch($prompt));
```

`Spectra::currentTags()` returns the tags a request tracked right now would get. To stop jobs inheriting tags, set `SPECTRA_PROPAGATE_TAGS=false`. `Spectra::clearGlobals()` forgets the inherited tags too.

<a name="filtering-by-tag"></a>
## Filtering by Tag

Tags are filterable in the Spectra dashboard's request explorer. You can filter by specific tags to narrow down requests to a particular feature, experiment, or environment. Tags are also available through the API at the `/api/tags` endpoint, which returns all known tag names for use in filter UI components.

<a name="storage-structure"></a>
## Storage Structure

Tags are stored in a normalized, many-to-many structure for efficient querying:

- **`spectra_tags`** — A lookup table of unique tag names (for example, `chat`, `production`). Each unique tag string is stored once.
- **`spectra_requests_tags`** — A pivot table linking requests to tags. Deleting a request cascades to its pivot entries.

This normalized design avoids storing duplicate tag strings across millions of request records and enables efficient tag-based queries and aggregations.
