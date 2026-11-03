<?php

declare(strict_types=1);

use Spectra\Data\ImageMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Replicate\Handlers\ImageHandler;
use Spectra\Providers\Replicate\Handlers\TextHandler;
use Spectra\Providers\Replicate\Handlers\VideoHandler;
use Spectra\Support\ProviderRegistry;

/**
 * The Replicate provider: its hosts, handler resolution and what each
 * handler extracts from a prediction.
 */
it('returns replicate as provider', function () {
    expect($this->replicateProvider()->getProvider())->toBe('replicate');
});

it('returns correct display name', function () {
    expect(app(ProviderRegistry::class)->displayName('replicate'))->toBe('Replicate');
});

it('returns correct hosts', function () {
    expect($this->replicateProvider()->getHosts())->toBe(['api.replicate.com']);
});

it('resolves image handler for predictions endpoint', function () {
    $handler = $this->replicateProvider()->resolveHandler('/v1/models/stability-ai/sdxl/predictions');

    expect($handler)->toBeInstanceOf(ImageHandler::class);
});

it('returns null for non-predictions endpoint', function () {
    $handler = $this->replicateProvider()->resolveHandler('/v1/something-else');

    expect($handler)->toBeNull();
});

it('image handler returns image model type', function () {
    $handler = new ImageHandler;

    expect($handler->modelType())->toBe(ModelType::Image);
});

it('returns no tokens for image response', function () {
    $handler = new ImageHandler;
    $metrics = $handler->extractMetrics([], []);

    expect($metrics)->toBeInstanceOf(Metrics::class)
        ->and($metrics->tokens)->toBeNull();
});

it('extracts metrics from prediction response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/replicate/prediction.json'), true);

    $handler = new ImageHandler;
    $metrics = $handler->extractMetrics(['input' => ['prompt' => 'test']], $response);

    expect($metrics)->toBeInstanceOf(Metrics::class)
        ->and($metrics->image)->toBeInstanceOf(ImageMetrics::class)
        ->and($metrics->image->count)->toBe(2)
        ->and($metrics->audio)->toBeNull();
});

it('extracts model from prediction response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/replicate/prediction.json'), true);

    $handler = new ImageHandler;

    expect($handler->extractModelFromResponse($response))->toBe('stability-ai/stable-diffusion');
});

it('extracts output urls from prediction response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/replicate/prediction.json'), true);

    $handler = new ImageHandler;
    $content = $handler->extractResponse($response);

    expect($content)->toContain('output1.png')
        ->and($content)->toContain('output2.png');
});

it('extracts finish reason (status) from prediction response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/replicate/prediction.json'), true);

    $handler = new ImageHandler;

    expect($handler->extractFinishReason($response))->toBe('succeeded');
});

it('matches replicate response shape', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/replicate/prediction.json'), true);

    $handler = new ImageHandler;

    expect($handler->matchesResponse($response))->toBeTrue();
});

it('does not match non-replicate response', function () {
    $handler = new ImageHandler;

    expect($handler->matchesResponse(['choices' => []]))->toBeFalse();
});

it('returns null response for empty output', function () {
    $handler = new ImageHandler;

    expect($handler->extractResponse(['output' => []]))->toBeNull();
    expect($handler->extractResponse([]))->toBeNull();
});

it('returns empty metrics for response without output', function () {
    $handler = new ImageHandler;
    $metrics = $handler->extractMetrics([], []);

    expect($metrics)->toBeInstanceOf(Metrics::class)
        ->and($metrics->image->count)->toBe(0)
        ->and($metrics->audio)->toBeNull();
});

it('resolves a handler for every prediction endpoint', function (string $endpoint) {
    expect($this->replicateProvider()->resolveHandler($endpoint))->not->toBeNull();
})->with([
    '/v1/models/black-forest-labs/flux-1.1-pro/predictions',
    '/v1/deployments/acme/my-flux/predictions',
    '/v1/predictions',
    '/v1/predictions/q6a2kzr8ksrme0cs9qwbx5c2y8',
    '/v1/predictions/q6a2kzr8ksrme0cs9qwbx5c2y8/cancel',
]);

it('classifies predictions by catalog type, file extension and token counts', function (array $data, string $handler) {
    $base = ['id' => 'p1', 'status' => 'succeeded', 'urls' => ['get' => 'https://api.replicate.com/v1/predictions/p1']];

    expect($this->replicateProvider()->resolveHandler('/v1/predictions/p1', array_merge($base, $data)))->toBeInstanceOf($handler);
})->with([
    'single image url of a catalog image model' => [['model' => 'recraft-ai/recraft-v3', 'output' => 'https://replicate.delivery/a/output'], ImageHandler::class],
    'single image url of an unknown model' => [['model' => 'someone/new-model', 'output' => 'https://replicate.delivery/a/out.png'], ImageHandler::class],
    'mp4 output of an unknown model' => [['model' => 'someone/video-model', 'output' => 'https://replicate.delivery/a/output.mp4'], VideoHandler::class],
    'token counts' => [['model' => 'meta/meta-llama-3-8b-instruct', 'output' => ['Hi'], 'metrics' => ['input_token_count' => 3, 'output_token_count' => 1]], TextHandler::class],
    'text pieces' => [['model' => 'someone/llm', 'output' => ['Hello', ' world']], TextHandler::class],
    'failed catalog video model' => [['model' => 'google/veo-3.1-lite', 'status' => 'failed', 'output' => null], VideoHandler::class],
]);

it('extracts the model from the request path or version', function () {
    $handler = new ImageHandler;

    expect($handler->extractModelFromRequest([], '/v1/models/black-forest-labs/flux-dev/predictions'))->toBe('black-forest-labs/flux-dev')
        ->and($handler->extractModelFromRequest(['version' => 'stability-ai/sdxl:7762fd07cf82c948538e41f63f77d685e02b063e37e496e96eefd46c929f9bdc'], '/v1/predictions'))->toBe('stability-ai/sdxl')
        ->and($handler->extractModelFromRequest(['version' => '7762fd07cf82c948538e41f63f77d685e02b063e37e496e96eefd46c929f9bdc'], '/v1/predictions'))->toBeNull()
        ->and($handler->extractModelFromRequest([], '/v1/predictions/abc'))->toBeNull();
});

it('skips predictions that are still running', function () {
    $handler = new ImageHandler;

    expect($handler->shouldSkipResponse(['status' => 'starting']))->toBeTrue()
        ->and($handler->shouldSkipResponse(['status' => 'processing']))->toBeTrue()
        ->and($handler->shouldSkipResponse(['status' => 'succeeded']))->toBeFalse()
        ->and($handler->shouldSkipResponse(['status' => 'failed']))->toBeFalse()
        ->and($handler->shouldSkipResponse(['status' => 'canceled']))->toBeFalse()
        // The prediction list shares the /v1/predictions path.
        ->and($handler->shouldSkipResponse(['next' => null, 'previous' => null, 'results' => []]))->toBeTrue();
});

it('reads the provider latency from iso timestamps or predict_time', function () {
    $handler = new ImageHandler;

    expect($handler->extractProviderLatency([
        'created_at' => '2026-10-15T10:00:00.123000Z',
        'started_at' => '2026-10-15T10:00:01.500000Z',
        'completed_at' => '2026-10-15T10:00:04.750000Z',
    ]))->toBe(3250)
        ->and($handler->extractProviderLatency(['created_at' => 'not a date', 'metrics' => ['predict_time' => 2.5]]))->toBe(2500)
        ->and($handler->extractProviderLatency([]))->toBeNull();
});
