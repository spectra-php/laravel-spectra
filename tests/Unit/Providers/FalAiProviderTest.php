<?php

use Spectra\Data\ImageMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\FalAi\Handlers\ImageHandler;

it('returns falai as provider', function () {
    expect($this->falAiProvider()->getProvider())->toBe('falai');
});

it('returns correct display name', function () {
    expect(app(\Spectra\Support\ProviderRegistry::class)->displayName('falai'))->toBe('fal.ai');
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

    expect($handler)->not->toBeInstanceOf(\Spectra\Contracts\ExtractsModelFromResponse::class);
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
