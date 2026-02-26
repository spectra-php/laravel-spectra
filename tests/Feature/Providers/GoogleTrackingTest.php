<?php

use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;
use Spectra\Providers\Google\Google;

/*
|--------------------------------------------------------------------------
| Google Response Recording Tests
|--------------------------------------------------------------------------
*/

it('records google gemini response to database', function () {
    $response = $this->loadMockResponse('google/response.json');

    Spectra::track('google', 'gemini-2.0-flash', function ($context) use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('google')
        ->and($record->model)->toBe('Gemini 2.0 Flash')
        ->and($record->status_code)->toBe(200)
        ->and($record->prompt_tokens)->toBe(15)
        ->and($record->completion_tokens)->toBe(42)
        ->and($record->total_tokens)->toBe(57)
        ->and($record->total_cost_in_cents)->toBeGreaterThan(0);

    $provider = new Google;
    $content = $provider->extractResponse($response);
    expect($content)->toContain('Artificial Intelligence')
        ->and($content)->toContain('Machine learning');
});

/*
|--------------------------------------------------------------------------
| Streaming Tests
|--------------------------------------------------------------------------
*/

it('records google streaming response to database', function () {
    $tracker = Spectra::stream('google', 'gemini-2.0-flash');

    $chunks = [
        ['candidates' => [['content' => ['parts' => [['text' => 'Hi there! ']]]]]],
        ['candidates' => [['content' => ['parts' => [['text' => 'How can I help?']]]]]],
        ['candidates' => [['content' => ['parts' => [['text' => '']]], 'finishReason' => 'STOP']],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 8,
                'totalTokenCount' => 18,
            ]],
    ];

    $content = '';
    foreach ($tracker->track($chunks) as $text) {
        $content .= $text;
    }

    $record = $tracker->finish();

    expect($record)->toBeInstanceOf(SpectraRequest::class)
        ->and($record->provider)->toBe('google')
        ->and($record->status_code)->toBe(200)
        ->and($record->prompt_tokens)->toBe(10)
        ->and($record->completion_tokens)->toBe(8)
        ->and($content)->toBe('Hi there! How can I help?');
});
