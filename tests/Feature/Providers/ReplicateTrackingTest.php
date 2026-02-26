<?php

use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;

it('records replicate prediction response to database', function () {
    $response = $this->loadMockResponse('replicate/prediction.json');

    // Remove ISO date strings that conflict with the persister's
    // numeric timestamp expectation (intended for Sora video responses)
    unset($response['created_at'], $response['completed_at']);

    $context = Spectra::startRequest('replicate', 'stability-ai/stable-diffusion');
    $context->endpoint = '/v1/models/stability-ai/stable-diffusion/predictions';

    $record = Spectra::recordSuccess($context, $response, [
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
    ]);

    expect($record)->toBeInstanceOf(SpectraRequest::class)
        ->and($record->provider)->toBe('replicate')
        ->and($record->status_code)->toBe(200)
        ->and($record->image_count)->toBe(2);
});
