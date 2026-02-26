<?php

use Spectra\Data\ImageMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Replicate\Handlers\ImageHandler;

it('returns replicate as provider', function () {
    expect($this->replicateProvider()->getProvider())->toBe('replicate');
});

it('returns correct display name', function () {
    expect(app(\Spectra\Support\ProviderRegistry::class)->displayName('replicate'))->toBe('Replicate');
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
