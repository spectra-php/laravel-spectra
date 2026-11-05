<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;

/**
 * A Gemini response recorded through Spectra::track(), where the handler is
 * picked by the response shape, with its model, tokens and cost; and Gemini
 * embeddings tracked through the HTTP client, whose responses carry no usage,
 * so the prompt is counted through the countTokens API when a Google key is
 * configured and stays at zero tokens without one.
 */
it('records a tracked gemini response with its model, tokens and cost', function () {
    $response = $this->loadMockResponse('google/response.json');

    Spectra::track('google', 'gemini-2.0-flash', fn () => $response);

    $record = SpectraRequest::query()->sole();

    // Gemini 2.0 Flash standard (in 10, out 40): (15×10 + 42×40) / 1M = 0.00183¢
    expect($record->provider)->toBe('google')
        ->and($record->model)->toBe('Gemini 2.0 Flash')
        ->and($record->model_type)->toBe('text')
        ->and($record->prompt_tokens)->toBe(15)
        ->and($record->completion_tokens)->toBe(42)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.00183, 1e-9);
});

describe('gemini embeddings through the HTTP client', function () {
    it('counts the prompt of an embedContent request through the countTokens API and bills it', function () {
        config(['spectra.api_keys.google' => 'test-google-key']);
        Http::fake([
            '*/models/gemini-embedding-001:embedContent' => Http::response($this->loadMockResponse('google/embed-content.json')),
            '*/models/gemini-embedding-001:countTokens*' => Http::response(['totalTokens' => 8, 'promptTokensDetails' => [['modality' => 'TEXT', 'tokenCount' => 8]]]),
        ]);
        $parts = [['text' => 'What is the meaning of life?']];

        Http::withHeaders(['x-goog-api-key' => 'test-google-key'])
            ->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent', [
                'model' => 'models/gemini-embedding-001',
                'content' => ['parts' => $parts],
            ]);

        $record = SpectraRequest::query()->sole();

        // Gemini Embedding standard: 8 tokens × 15 cents per 1M = 0.00012¢
        expect($record->provider)->toBe('google')
            ->and($record->model)->toBe('Gemini Embedding')
            ->and($record->model_type)->toBe('embedding')
            ->and($record->prompt_tokens)->toBe(8)
            ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.00012, 1e-12)
            ->and(Http::recorded(fn (Request $request) => Str::contains($request->url(), ':countTokens')
                && $request->data() === ['contents' => [['parts' => $parts]]]))->toHaveCount(1);
    });

    it('sums the countTokens results of a batchEmbedContents request', function () {
        config(['spectra.api_keys.google' => 'test-google-key']);
        Http::fake([
            '*/models/gemini-embedding-001:batchEmbedContents' => Http::response($this->loadMockResponse('google/batch-embed-contents.json')),
            '*/models/gemini-embedding-001:countTokens*' => Http::sequence()->push(['totalTokens' => 5])->push(['totalTokens' => 7]),
        ]);

        Http::withHeaders(['x-goog-api-key' => 'test-google-key'])
            ->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:batchEmbedContents', [
                'requests' => [
                    ['model' => 'models/gemini-embedding-001', 'content' => ['parts' => [['text' => 'What is the meaning of life?']]]],
                    ['model' => 'models/gemini-embedding-001', 'content' => ['parts' => [['text' => 'How much wood would a woodchuck chuck?']]]],
                ],
            ]);

        $record = SpectraRequest::query()->sole();

        // Gemini Embedding standard: (5 + 7) tokens × 15 cents per 1M = 0.00018¢
        expect($record->model)->toBe('Gemini Embedding')
            ->and($record->model_type)->toBe('embedding')
            ->and($record->prompt_tokens)->toBe(12)
            ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.00018, 1e-12)
            ->and(Http::recorded(fn (Request $request) => Str::contains($request->url(), ':countTokens')))->toHaveCount(2);
    });

    it('records an embedding with zero tokens and no cost when no Google key is configured', function () {
        Http::fake(['*/models/gemini-embedding-001:embedContent' => Http::response($this->loadMockResponse('google/embed-content.json'))]);

        Http::post('https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent', [
            'content' => ['parts' => [['text' => 'What is the meaning of life?']]],
        ]);

        $record = SpectraRequest::query()->sole();

        // Without a key the countTokens API cannot be asked, so the prompt size stays unknown and nothing is billed.
        expect($record->provider)->toBe('google')
            ->and($record->model)->toBe('Gemini Embedding')
            ->and($record->model_type)->toBe('embedding')
            ->and($record->prompt_tokens)->toBe(0)
            ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.0, 1e-12)
            ->and(Http::recorded(fn (Request $request) => Str::contains($request->url(), ':countTokens')))->toBeEmpty();
    });
});
