<?php

declare(strict_types=1);

use Spectra\Providers\OpenAI\Handlers\VideoHandler;

/**
 * The OpenAI video handler: the content download it leaves untracked, the
 * unfinished jobs it skips, and the duration, expiry and generation time it
 * reads from a job.
 */
it('video handler does not match /v1/videos/{id}/content endpoint', function () {
    $handler = new VideoHandler;

    expect($handler->matchesEndpoint('/v1/videos/video_abc123/content'))->toBeFalse();
});

it('skips queued video responses', function () {
    $response = $this->loadMockResponse('openai/video_queued.json');

    expect((new VideoHandler)->shouldSkipResponse($response))->toBeTrue();
});

it('returns null duration when seconds not present', function () {
    $metrics = (new VideoHandler)->extractMetrics([], ['status' => 'completed']);

    expect($metrics->video->count)->toBe(1)
        ->and($metrics->video->durationSeconds)->toBeNull();
});

it('extracts expires_at from completed video response', function () {
    $response = $this->loadMockResponse('openai/video_completed.json');

    $expiresAt = (new VideoHandler)->extractExpiresAt($response);

    expect($expiresAt->timestamp)->toBe(1770496474);
});

it('returns null expires_at when not present', function () {
    $handler = new VideoHandler;

    expect($handler->extractExpiresAt([]))->toBeNull()
        ->and($handler->extractExpiresAt(['status' => 'completed']))->toBeNull();
});

it('measures generation time from ISO-8601 timestamps', function () {
    $latency = (new VideoHandler)->extractProviderLatency([
        'created_at' => '2026-09-01T10:00:00.250Z',
        'completed_at' => '2026-09-01T10:01:30.750Z',
    ]);

    expect($latency)->toBe(90_500);
});

it('returns no generation time for an unfinished video', function () {
    $response = $this->loadMockResponse('openai/video_queued.json');

    expect((new VideoHandler)->extractProviderLatency($response))->toBeNull();
});
