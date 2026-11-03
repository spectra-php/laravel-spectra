<?php

declare(strict_types=1);

use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Data\ImageMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\FalAi\Handlers\ImageHandler;
use Spectra\Providers\FalAi\Handlers\QueueStatusHandler;
use Spectra\Providers\FalAi\Handlers\VideoHandler;
use Spectra\Support\ProviderRegistry;

/**
 * The fal.ai provider: its hosts, handler resolution by endpoint, and the
 * metrics, model and media the image handler extracts from sync and queue
 * responses.
 */
it('returns falai as provider', function () {
    expect($this->falAiProvider()->getProvider())->toBe('falai');
});

it('returns correct display name', function () {
    expect(app(ProviderRegistry::class)->displayName('falai'))->toBe('fal.ai');
});

it('returns correct hosts', function () {
    expect($this->falAiProvider()->getHosts())->toBe(['fal.run', 'queue.fal.run']);
});

it('resolves image handler for sync endpoint', function () {
    $handler = $this->falAiProvider()->resolveHandler('/fal-ai/fast-sdxl');

    expect($handler)->toBeInstanceOf(ImageHandler::class);
});

it('resolves image handler for three-segment endpoint', function () {
    $handler = $this->falAiProvider()->resolveHandler('/fal-ai/flux/dev');

    expect($handler)->toBeInstanceOf(ImageHandler::class);
});

it('resolves image handler for four-segment endpoint', function () {
    $handler = $this->falAiProvider()->resolveHandler('/fal-ai/recraft/v3/text-to-image');

    expect($handler)->toBeInstanceOf(ImageHandler::class);
});

it('returns null for root endpoint', function () {
    $handler = $this->falAiProvider()->resolveHandler('/');

    expect($handler)->toBeNull();
});

it('image handler returns image model type', function () {
    $handler = new ImageHandler;

    expect($handler->modelType())->toBe(ModelType::Image);
});

it('extracts metrics from sync response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/falai/sync-image.json'), true);

    $handler = new ImageHandler;
    $metrics = $handler->extractMetrics(['prompt' => 'a cat'], $response);

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->image)->toBeInstanceOf(ImageMetrics::class);
    expect($metrics->image->count)->toBe(1);
    expect($metrics->tokens)->toBeNull();
});

it('extracts metrics from queue response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/falai/queue-image.json'), true);

    $handler = new ImageHandler;
    $metrics = $handler->extractMetrics(['prompt' => 'test'], $response);

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->image)->toBeInstanceOf(ImageMetrics::class);
    expect($metrics->image->count)->toBe(1);
});

it('extracts model from endpoint', function () {
    $handler = new ImageHandler;

    expect($handler->extractModelFromRequest([], '/fal-ai/flux/dev'))->toBe('fal-ai/flux/dev');
    expect($handler->extractModelFromRequest([], '/fal-ai/fast-sdxl'))->toBe('fal-ai/fast-sdxl');
    expect($handler->extractModelFromRequest([], '/fal-ai/recraft/v3/text-to-image'))->toBe('fal-ai/recraft/v3/text-to-image');
});

it('does not implement ExtractsModelFromResponse', function () {
    $handler = new ImageHandler;

    expect($handler)->not->toBeInstanceOf(ExtractsModelFromResponse::class);
});

it('extracts image urls from sync response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/falai/sync-image.json'), true);

    $handler = new ImageHandler;
    $content = $handler->extractResponse($response);

    expect($content)->toContain('v3.fal.media');
});

it('extracts image urls from queue response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/falai/queue-image.json'), true);

    $handler = new ImageHandler;
    $content = $handler->extractResponse($response);

    expect($content)->toContain('url.to/image.png');
});

it('extracts finish reason from queue response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/falai/queue-image.json'), true);

    $handler = new ImageHandler;

    expect($handler->extractFinishReason($response))->toBe('OK');
});

it('returns null finish reason for sync response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/falai/sync-image.json'), true);

    $handler = new ImageHandler;

    expect($handler->extractFinishReason($response))->toBeNull();
});

it('returns null response for empty images', function () {
    $handler = new ImageHandler;

    expect($handler->extractResponse(['images' => []]))->toBeNull();
    expect($handler->extractResponse([]))->toBeNull();
});

it('returns empty metrics for response without images', function () {
    $handler = new ImageHandler;
    $metrics = $handler->extractMetrics([], []);

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->image->count)->toBe(0);
    expect($metrics->tokens)->toBeNull();
});

it('extracts model from request via provider', function () {
    $provider = $this->falAiProvider();

    expect($provider->extractModelFromRequest([], '/fal-ai/flux-pro/kontext'))->toBe('fal-ai/flux-pro/kontext');
});

it('sends queue results to the result handlers and status calls to the status handler', function (string $endpoint, string $handler) {
    expect($this->falAiProvider()->resolveHandler($endpoint))->toBeInstanceOf($handler);
})->with([
    'result with sub-path' => ['/fal-ai/flux/dev/requests/764cabcf-b745-4b3e-ae38-1200304cf45b', ImageHandler::class],
    'result without sub-path' => ['/fal-ai/flux/requests/764cabcf-b745-4b3e-ae38-1200304cf45b', ImageHandler::class],
    'result /response' => ['/fal-ai/flux/dev/requests/764cabcf-b745-4b3e-ae38-1200304cf45b/response', ImageHandler::class],
    'status' => ['/fal-ai/flux/dev/requests/764cabcf-b745-4b3e-ae38-1200304cf45b/status', QueueStatusHandler::class],
    'status stream' => ['/fal-ai/flux/requests/764cabcf-b745-4b3e-ae38-1200304cf45b/status/stream', QueueStatusHandler::class],
    'cancel' => ['/fal-ai/flux/requests/764cabcf-b745-4b3e-ae38-1200304cf45b/cancel', QueueStatusHandler::class],
]);

it('picks the video handler for video results', function () {
    $body = json_decode(file_get_contents(__DIR__.'/../../responses/falai/queue-result-video.json'), true);

    expect($this->falAiProvider()->resolveHandler('/fal-ai/kling-video/requests/abc', $body))->toBeInstanceOf(VideoHandler::class);
});

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
    $load = fn (string $file) => json_decode(file_get_contents(__DIR__."/../../responses/falai/{$file}"), true);

    expect($handler->shouldSkipResponse($load('queue-submit.json')))->toBeTrue()
        ->and($handler->shouldSkipResponse($load('queue-status-in-progress.json')))->toBeTrue()
        ->and($handler->shouldSkipResponse($load('queue-status-completed.json')))->toBeTrue()
        ->and($handler->shouldSkipResponse($load('queue-result-flux-dev.json')))->toBeFalse()
        ->and($handler->shouldSkipResponse($load('sync-image.json')))->toBeFalse();
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
