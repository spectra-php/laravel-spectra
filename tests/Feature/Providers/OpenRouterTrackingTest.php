<?php

declare(strict_types=1);

use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;

it('records openrouter chat response to database', function () {
    $response = $this->loadMockResponse('openrouter/chat.json');

    Spectra::track('openrouter', 'openai/gpt-5-mini', function ($context) use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('openrouter')
        ->and($record->model_type)->toBe('text')
        ->and($record->status_code)->toBe(200)
        ->and($record->prompt_tokens)->toBe(25)
        ->and($record->completion_tokens)->toBe(15)
        ->and($record->total_cost_in_cents)->toBeGreaterThan(0);
});

it('resolves image responses to the image handler, not the chat handler', function () {
    // Regression: both OpenRouter handlers match /api/v1/chat/completions, and
    // the generic ChatHandler accepts any choices+usage body — with the wrong
    // registration order it swallowed image responses.
    $response = $this->loadMockResponse('openrouter/image.json');

    Spectra::track('openrouter', 'google/gemini-2.5-flash-image-preview', function ($context) use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('openrouter')
        ->and($record->model_type)->toBe('image')
        ->and($record->image_count)->toBe(2)
        ->and($record->total_cost_in_cents)->toBeGreaterThan(0);
});
