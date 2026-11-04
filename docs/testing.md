# Testing

Spectra ships a test double called `SpectraFake`. It captures tracked requests in memory without touching the database, so you can assert on your app's AI tracking in unit and feature tests without migrations, seeding, or cleanup.

The fake catches the same tracking calls that would normally save records to `spectra_requests`, but keeps them in memory instead. You can then assert on the number of requests, the providers and models used, whether requests succeeded or failed, and the tags or token counts that were recorded.

<a name="setting-up-the-fake"></a>
## Setting Up the Fake

Call `Spectra::fake()` at the start of your test to swap the real Spectra manager for the in-memory fake. It returns the fake, which has all the assertion and inspection methods:

```php
use Spectra\Facades\Spectra;

it('tracks the AI request', function () {
    $fake = Spectra::fake();

    // ... your code that makes an AI request ...

    $fake->assertRequestCount(1);
    $fake->assertProviderUsed('openai');
    $fake->assertModelUsed('gpt-4o');
});
```

<a name="available-assertions"></a>
## Available Assertions

### Request Count

Assert that a given number of requests were tracked, or that none were:

```php
$fake->assertRequestCount(3);     // Exactly 3 requests tracked
$fake->assertNothingTracked();    // No requests tracked
```

### Provider and Model

Assert that a provider or model was used during the test:

```php
$fake->assertProviderUsed('openai');
$fake->assertProviderUsed('anthropic');
$fake->assertModelUsed('gpt-4o');
$fake->assertModelUsed('claude-sonnet-4-20250514');
```

### Success and Failure

Assert on the outcome of tracked requests:

```php
$fake->assertSuccessful();        // At least one successful request
$fake->assertFailed();            // At least one failed request
```

### Tags

Assert that requests were tracked with specific tags:

```php
$fake->assertTrackedWithTags(['chat', 'high-priority']);
```

### Token Usage

Assert on the total token count across all tracked requests:

```php
$fake->assertTotalTokens(1500);
```

### Spans

The fake runs `Spectra::span()` callbacks and remembers the spans instead of storing them:

```php
$fake->assertSpanRecorded('retrieve-documents');

$fake->assertSpanRecorded('answer-question', fn (array $span) => $span['status'] === 'ok');

$spans = $fake->getSpans(); // name, attributes, status ('ok' or 'error') and exception of each
```

<a name="scores"></a>
### Scores

The fake checks `Spectra::score()` the same way and keeps the scores in memory instead of saving them. Without a target, a score goes to the current trace (or `fake-trace`):

```php
$fake->assertScored('helpful');

$fake->assertScored('helpful', fn (array $score) => $score['value'] === 1.0 && $score['comment'] === 'Thanks');

$fake->assertNotScored('answer_quality');

$scores = $fake->getScores(); // the columns each score would be saved with
```

### Custom Assertions

For more complex checks, pass a callback that receives the recorded request data and returns a boolean:

```php
$fake->assertTracked(function ($recorded) {
    return $recorded['context']->provider === 'openai'
        && $recorded['context']->model === 'gpt-4o'
        && $recorded['context']->promptTokens > 0;
});
```

<a name="inspecting-recorded-requests"></a>
## Inspecting Recorded Requests

You can also read the raw recorded data yourself. Each recorded request is an array with the provider, model, usage metrics, cost, and other tracking data:

```php
$fake = Spectra::fake();

// ... make requests ...

$all = $fake->getRecorded();         // All recorded requests
$successful = $fake->getSuccessful(); // Only successful requests
$failed = $fake->getFailed();         // Only failed requests

foreach ($all as $request) {
    dump($request['provider'], $request['model']);
}
```

<a name="resetting-state"></a>
## Resetting State

The fake can reset its state during a test. This helps when one test checks several phases:

```php
$fake->reset();     // Clear all recorded requests
$fake->disable();   // Stop tracking entirely
$fake->enable();    // Re-enable tracking
```

<a name="disabling-tracking-in-tests"></a>
## Disabling Tracking in Tests

To turn off tracking for your whole test suite (for example, to avoid database writes in tests that don't assert on tracking), disable Spectra in your base test case:

```php
protected function setUp(): void
{
    parent::setUp();
    config(['spectra.enabled' => false]);
}
```

Calling `Spectra::fake()` has a similar effect: it captures requests in memory instead of writing them to the database. This is usually the better choice, because you can add tracking assertions later without any extra setup.
