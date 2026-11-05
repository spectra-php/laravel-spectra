<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;

/**
 * An Ollama chat response recorded through Spectra::track(), and embedding
 * requests to the native `/api/embed` and legacy `/api/embeddings` endpoints
 * tracked through the HTTP client, with their tokens and no cost, since local
 * models are free.
 */
it('records a tracked ollama chat response with its tokens at zero cost', function () {
    $response = $this->loadMockResponse('ollama/chat.json');

    Spectra::track('ollama', 'llama3.2', fn () => $response);

    $record = SpectraRequest::query()->sole();

    // Ollama runs locally, so requests are tracked but deliberately cost $0.
    expect($record->provider)->toBe('ollama')
        ->and($record->model)->toBe('llama3.2')
        ->and($record->model_type)->toBe('text')
        ->and($record->prompt_tokens)->toBe(26)
        ->and($record->completion_tokens)->toBe(12)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.0, 1e-12);
});

it('records an ollama embed request through the HTTP client with its prompt tokens at zero cost', function () {
    Http::fake(['localhost:11434/api/embed' => Http::response($this->loadMockResponse('ollama/embed.json'))]);

    Http::post('http://localhost:11434/api/embed', ['model' => 'nomic-embed-text', 'input' => 'Why is the sky blue?']);

    $record = SpectraRequest::query()->sole();

    // Ollama runs locally, so the 8 prompt tokens are tracked but deliberately cost $0.
    expect($record->provider)->toBe('ollama')
        ->and($record->model)->toBe('nomic-embed-text')
        ->and($record->model_type)->toBe('embedding')
        ->and($record->prompt_tokens)->toBe(8)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.0, 1e-12);
});

it('records a legacy ollama embeddings request through the HTTP client at zero cost', function () {
    Http::fake(['localhost:11434/api/embeddings' => Http::response($this->loadMockResponse('ollama/embeddings.json'))]);

    Http::post('http://localhost:11434/api/embeddings', ['model' => 'nomic-embed-text', 'prompt' => 'Why is the sky blue?']);

    $record = SpectraRequest::query()->sole();

    // The legacy response names neither the model nor a token count, so the model comes from the request and the prompt size stays unknown.
    expect($record->provider)->toBe('ollama')
        ->and($record->model)->toBe('nomic-embed-text')
        ->and($record->model_type)->toBe('embedding')
        ->and($record->prompt_tokens)->toBe(0)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.0, 1e-12);
});
