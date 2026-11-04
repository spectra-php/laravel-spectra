<?php

declare(strict_types=1);

/**
 * Payload sampling: storage.sampling.rate keeps the request and response
 * bodies of only that share of traces, all calls of a trace together, while
 * every call is still recorded with its tokens and cost. Failed calls keep
 * their bodies unless keep_failures is off.
 */
use Illuminate\Support\Str;
use Spectra\Models\SpectraRequest;
use Spectra\Spectra;

/**
 * Track one call in the given trace, failed or not.
 */
function trackSampledCall(string $traceId, bool $failed = false): SpectraRequest
{
    $manager = app(Spectra::class);
    $context = $manager->startRequest('openai', 'gpt-4o', [
        'trace_id' => $traceId,
        'request_data' => ['model' => 'gpt-4o', 'messages' => [['role' => 'user', 'content' => 'Hi']]],
    ]);

    return $failed
        ? $manager->recordFailure($context, new RuntimeException('Overloaded'), 529)
        : $manager->recordSuccess($context, ['choices' => [['message' => ['content' => 'Hello']]]], ['prompt_tokens' => 12, 'completion_tokens' => 3]);
}

it('keeps every body at the default rate of 1', function () {
    $call = trackSampledCall('trace-a');

    expect($call->request)->not->toBeNull()
        ->and($call->response)->not->toBeNull();
});

it('drops the bodies at rate 0 but still records the usage and cost', function () {
    config()->set('spectra.storage.sampling.rate', 0.0);

    $call = trackSampledCall('trace-a')->refresh();

    expect($call->request)->toBeNull()
        ->and($call->response)->toBeNull()
        ->and($call->prompt_tokens)->toBe(12)
        ->and($call->total_cost_in_cents)->toBeGreaterThan(0.0);
});

it('keeps a failed call\'s bodies unless keep_failures is off', function () {
    config()->set('spectra.storage.sampling.rate', 0.0);

    expect(trackSampledCall('trace-a', failed: true)->refresh()->request)->not->toBeNull();

    config()->set('spectra.storage.sampling.keep_failures', false);

    expect(trackSampledCall('trace-b', failed: true)->refresh()->request)->toBeNull();
});

it('keeps or drops all calls of a trace together, near the configured share', function () {
    config()->set('spectra.storage.sampling.rate', 0.25);

    $traces = collect(range(1, 400))->map(fn () => (string) Str::uuid());
    $kept = $traces->filter(function (string $traceId) {
        $first = trackSampledCall($traceId)->refresh()->request !== null;
        $second = trackSampledCall($traceId)->refresh()->request !== null;

        expect($second)->toBe($first);

        return $first;
    });

    expect($kept->count())->toBeGreaterThan(60)->toBeLessThan(140);
});
