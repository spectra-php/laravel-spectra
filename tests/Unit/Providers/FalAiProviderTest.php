<?php

declare(strict_types=1);

use Spectra\Providers\FalAi\Handlers\ImageHandler;
use Spectra\Providers\FalAi\Handlers\QueueStatusHandler;

/**
 * The fal.ai provider: which handler a model path or queue path reaches,
 * the model and request id read from the path, skipped queue replies, and
 * the megapixels the image handler bills.
 */
it('returns null for root endpoint', function () {
    $handler = $this->falAiProvider()->resolveHandler('/');

    expect($handler)->toBeNull();
});

it('extracts image urls from sync response', function () {
    $response = $this->loadMockResponse('falai/sync-image.json');

    $content = (new ImageHandler)->extractResponse($response);

    expect($content)->toContain('v3.fal.media');
});

it('sends queue results to the result handlers and status calls to the status handler', function (string $endpoint, string $handler) {
    expect($this->falAiProvider()->resolveHandler($endpoint))->toBeInstanceOf($handler);
})->with([
    'result without sub-path' => ['/fal-ai/flux/requests/764cabcf-b745-4b3e-ae38-1200304cf45b', ImageHandler::class],
    'result /response' => ['/fal-ai/flux/dev/requests/764cabcf-b745-4b3e-ae38-1200304cf45b/response', ImageHandler::class],
    'status' => ['/fal-ai/flux/dev/requests/764cabcf-b745-4b3e-ae38-1200304cf45b/status', QueueStatusHandler::class],
    'cancel' => ['/fal-ai/flux/requests/764cabcf-b745-4b3e-ae38-1200304cf45b/cancel', QueueStatusHandler::class],
]);

it('takes the model from the path before /requests/', function () {
    $handler = new ImageHandler;

    expect($handler->extractModelFromRequest([], '/fal-ai/flux/dev/requests/abc'))->toBe('fal-ai/flux/dev')
        ->and($handler->extractModelFromRequest([], '/fal-ai/flux/requests/abc/response'))->toBe('fal-ai/flux')
        ->and($handler->extractModelFromRequest([], '/workflows/acme/pipeline/requests/abc'))->toBe('workflows/acme/pipeline');
});

it('uses the request id of a queued result as the response id', function () {
    $provider = $this->falAiProvider();

    expect($provider->extractResponseId(['images' => []], '/fal-ai/flux/requests/764cabcf'))->toBe('764cabcf')
        ->and($provider->extractResponseId(['images' => []], '/fal-ai/flux/dev/requests/764cabcf/response'))->toBe('764cabcf')
        ->and($provider->extractResponseId(['images' => []], '/fal-ai/flux/dev'))->toBeNull();
});

it('skips queue receipts and status replies', function () {
    $handler = new ImageHandler;

    expect($handler->shouldSkipResponse($this->loadMockResponse('falai/queue-submit.json')))->toBeTrue()
        ->and($handler->shouldSkipResponse($this->loadMockResponse('falai/queue-status-in-progress.json')))->toBeTrue()
        ->and($handler->shouldSkipResponse($this->loadMockResponse('falai/queue-status-completed.json')))->toBeTrue()
        ->and($handler->shouldSkipResponse($this->loadMockResponse('falai/queue-result-flux-dev.json')))->toBeFalse()
        ->and($handler->shouldSkipResponse($this->loadMockResponse('falai/sync-image.json')))->toBeFalse();
});

it('rounds each image up to whole 1024 x 1024 megapixels', function () {
    $metrics = (new ImageHandler)->extractMetrics([], ['images' => [
        ['url' => 'a', 'width' => 1024, 'height' => 1024],
        ['url' => 'b', 'width' => 1920, 'height' => 1080],
        ['url' => 'c', 'width' => 512, 'height' => 512],
    ]]);

    expect($metrics->image->count)->toBe(3)
        ->and($metrics->image->megapixels)->toBe(4.0);
});

it('leaves megapixels unknown when an image has no size', function () {
    $metrics = (new ImageHandler)->extractMetrics([], ['images' => [['url' => 'a', 'width' => null, 'height' => null]]]);

    expect($metrics->image->megapixels)->toBeNull();
});
