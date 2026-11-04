# Tags

Tags are simple string labels you attach to tracked AI requests. You use them to filter, group, and analyze requests in the dashboard, for example by feature, environment, priority, experiment, customer tier, or anything else that matters to your app.

Spectra reads the provider and model from each request automatically. Tags are up to you: you decide what to tag, when, and how to use the tags for filtering and reporting.

If you need structured key-value context instead of simple labels, use [Metadata](/metadata).

<a name="attaching-tags"></a>
## Attaching Tags

With manual tracking, add tags through the `RequestContext` passed to `Spectra::track()`:

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

Global tags apply to every tracked request in the current request or queued job. They are reset between queued jobs and Octane requests. Use them to add context like the environment without tagging each request yourself:

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

You can filter by tag in the dashboard's request explorer, to narrow the list down to one feature, experiment, or environment. Each row shows its tags. The `/api/tags` endpoint returns all known tag names, for use in filter UI components.

<img class="screenshot" src="/images/dashboard/requests.png" alt="The requests list with a tags column" />

<a name="storage-structure"></a>
## Storage Structure

Tags are stored in a normalized many-to-many structure, so they are fast to query:

- **`spectra_tags`**: a lookup table of unique tag names (for example, `chat`, `production`). Each tag name is stored once.
- **`spectra_requests_tags`**: a pivot table that links requests to tags. Deleting a request also deletes its pivot rows.

This way the same tag name isn't stored again on millions of request rows, and queries and totals by tag stay fast.
