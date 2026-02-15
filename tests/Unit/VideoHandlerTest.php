<?php

use Illuminate\Support\Carbon;
use Spectra\Contracts\HasExpiration;
use Spectra\Contracts\SkipsResponse;
use Spectra\Data\Metrics;
use Spectra\Data\VideoMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\OpenAI\Handlers\VideoHandler;

// --- VideoHandler Basics ---

it('video handler returns video model type', function () {
    $handler = new VideoHandler;

    expect($handler->modelType())->toBe(ModelType::Video);
});

it('video handler implements SkipsResponse', function () {
    $handler = new VideoHandler;

    expect($handler)->toBeInstanceOf(SkipsResponse::class);
});

// --- matchesEndpoint ---

it('video handler matches /v1/videos endpoint', function () {
    $handler = new VideoHandler;

    expect($handler->matchesEndpoint('/v1/videos'))->toBeTrue();
});

it('video handler matches /v1/videos/{id} endpoint', function () {
    $handler = new VideoHandler;

    expect($handler->matchesEndpoint('/v1/videos/video_abc123'))->toBeTrue();
});

it('video handler does not match /v1/videos/{id}/content endpoint', function () {
    $handler = new VideoHandler;

    expect($handler->matchesEndpoint('/v1/videos/video_abc123/content'))->toBeFalse();
});

it('video handler does not match unrelated endpoints', function () {
    $handler = new VideoHandler;

    expect($handler->matchesEndpoint('/v1/chat/completions'))->toBeFalse();
    expect($handler->matchesEndpoint('/v1/images/generations'))->toBeFalse();
});

// --- shouldSkipResponse ---

it('skips queued video responses', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/video_queued.json'), true);
    $handler = new VideoHandler;

    expect($handler->shouldSkipResponse($response))->toBeTrue();
});

it('skips in_progress video responses', function () {
    $handler = new VideoHandler;

    expect($handler->shouldSkipResponse(['status' => 'in_progress']))->toBeTrue();
});

it('skips failed video responses', function () {
    $handler = new VideoHandler;

    expect($handler->shouldSkipResponse(['status' => 'failed']))->toBeTrue();
});

it('does not skip completed video responses', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/video_completed.json'), true);
    $handler = new VideoHandler;

    expect($handler->shouldSkipResponse($response))->toBeFalse();
});

it('skips responses without status field', function () {
    $handler = new VideoHandler;

    expect($handler->shouldSkipResponse([]))->toBeTrue();
});

// --- extractMetrics ---

it('extracts video metrics from completed response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/video_completed.json'), true);
    $handler = new VideoHandler;

    $metrics = $handler->extractMetrics(['prompt' => 'A futuristic city'], $response);

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->video)->toBeInstanceOf(VideoMetrics::class);
    expect($metrics->video->count)->toBe(1);
    expect($metrics->video->durationSeconds)->toBe(10.0);
    expect($metrics->tokens)->toBeNull();
});

it('returns null duration when seconds not present', function () {
    $handler = new VideoHandler;
    $metrics = $handler->extractMetrics([], ['status' => 'completed']);

    expect($metrics->video->count)->toBe(1);
    expect($metrics->video->durationSeconds)->toBeNull();
});

// --- extractModel ---

it('extracts model from video response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/video_completed.json'), true);
    $handler = new VideoHandler;

    expect($handler->extractModel($response))->toBe('sora-2');
});

it('returns null model when not present in video response', function () {
    $handler = new VideoHandler;

    expect($handler->extractModel([]))->toBeNull();
});

// --- extractResponse ---

it('extracts prompt text from video response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/video_completed.json'), true);
    $handler = new VideoHandler;

    expect($handler->extractResponse($response))->toBe('A futuristic city at sunset');
});

it('returns null when video response has no prompt', function () {
    $handler = new VideoHandler;

    expect($handler->extractResponse([]))->toBeNull();
});

// --- HasExpiration ---

it('video handler implements HasExpiration', function () {
    $handler = new VideoHandler;

    expect($handler)->toBeInstanceOf(HasExpiration::class);
});

it('extracts expires_at from completed video response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/video_completed.json'), true);
    $handler = new VideoHandler;

    $expiresAt = $handler->extractExpiresAt($response);

    expect($expiresAt)->toBeInstanceOf(Carbon::class);
    expect($expiresAt->timestamp)->toBe(1770496474);
});

it('returns null expires_at when not present', function () {
    $handler = new VideoHandler;

    expect($handler->extractExpiresAt([]))->toBeNull();
    expect($handler->extractExpiresAt(['status' => 'completed']))->toBeNull();
});
