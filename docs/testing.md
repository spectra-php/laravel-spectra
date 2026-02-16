# Testing

Spectra provides a test double called `SpectraFake` that captures tracked requests in memory without touching the database. This allows you to write assertions against your application's AI tracking behavior in unit and feature tests without requiring database migrations, seeding, or cleanup.

The fake intercepts the same tracking calls that would normally persist records to `spectra_requests`, but holds them in an in-memory collection instead. You can then assert on the number of requests, which providers and models were used, whether requests succeeded or failed, and any tags or token counts that were recorded.

<a name="setting-up-the-fake"></a>
## Setting Up the Fake

Call `Spectra::fake()` at the beginning of your test to replace the real Spectra manager with the in-memory fake. The method returns the fake instance, which exposes all assertion and inspection methods:

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

Assert that a specific number of requests were tracked, or that no requests were tracked at all:

```php
$fake->assertRequestCount(3);     // Exactly 3 requests tracked
$fake->assertNothingTracked();    // No requests tracked
```

### Provider and Model

Assert that specific providers or models were used during the test:

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

### Custom Assertions

For more complex validation, pass a callback that receives the recorded request data and returns a boolean:

```php
$fake->assertTracked(function ($recorded) {
    return $recorded['context']->provider === 'openai'
        && $recorded['context']->model === 'gpt-4o'
        && $recorded['context']->promptTokens > 0;
});
```

<a name="inspecting-recorded-requests"></a>
## Inspecting Recorded Requests

Beyond assertions, you can access the raw recorded request data for manual inspection. Each recorded request is an array containing the provider, model, usage metrics, cost, and other tracking metadata:

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

The fake provides methods to reset its internal state during a test, which is useful when you want to verify behavior across multiple phases of a single test:

```php
$fake->reset();     // Clear all recorded requests
$fake->disable();   // Stop tracking entirely
$fake->enable();    // Re-enable tracking
```

<a name="disabling-tracking-in-tests"></a>
## Disabling Tracking in Tests

If you want to prevent Spectra from tracking in your entire test suite — for example, to avoid database writes in tests that don't need tracking assertions — you can disable it globally in your base test case:

```php
protected function setUp(): void
{
    parent::setUp();
    config(['spectra.enabled' => false]);
}
```

Alternatively, calling `Spectra::fake()` achieves a similar effect by capturing requests in memory instead of writing to the database. This is generally the preferred approach, since it allows you to optionally add tracking assertions without any additional setup.
