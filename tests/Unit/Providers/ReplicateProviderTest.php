<?php

declare(strict_types=1);

use Spectra\Providers\Replicate\Handlers\ImageHandler;
use Spectra\Providers\Replicate\Handlers\TextHandler;
use Spectra\Providers\Replicate\Handlers\VideoHandler;

/**
 * The Replicate provider: which handler a prediction reaches (catalog type,
 * file extension, token counts), the model read from the path or version,
 * skipped running predictions and the provider latency.
 */
it('extracts output urls from prediction response', function () {
    $response = $this->loadMockResponse('replicate/prediction.json');

    $content = (new ImageHandler)->extractResponse($response);

    expect($content)->toContain('output1.png')
        ->and($content)->toContain('output2.png');
});

it('classifies predictions by catalog type, file extension and token counts', function (array $data, string $handler) {
    $base = ['id' => 'p1', 'status' => 'succeeded', 'urls' => ['get' => 'https://api.replicate.com/v1/predictions/p1']];

    expect($this->replicateProvider()->resolveHandler('/v1/predictions/p1', array_merge($base, $data)))->toBeInstanceOf($handler);
})->with([
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
