<?php

use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;

it('records fal.ai image generation response to database', function (string $fixture, string $endpoint, int $expectedImages) {
    $response = $this->loadMockResponse($fixture);

    $context = Spectra::startRequest('falai', 'fal-ai/fast-sdxl');
    $context->endpoint = $endpoint;

    $record = Spectra::recordSuccess($context, $response, [
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
    ]);

    expect($record)->toBeInstanceOf(SpectraRequest::class)
        ->and($record->provider)->toBe('falai')
        ->and($record->status_code)->toBe(200)
        ->and($record->image_count)->toBe($expectedImages);
})->with([
    'sync response' => ['falai/sync-image.json', '/fal-ai/fast-sdxl', 1],
    'queue response' => ['falai/queue-image.json', '/fal-ai/fast-sdxl', 1],
]);
