<?php

declare(strict_types=1);

use Spectra\Facades\Spectra as SpectraFacade;
use Spectra\Models\SpectraRequest;
use Spectra\Spectra;

/**
 * The Spectra manager and facade: recording a request's success or failure
 * by hand, track(), clearing the globals, and the testing fake.
 */
it('records the status and token counts of a successful request', function () {
    $manager = app(Spectra::class);
    $context = $manager->startRequest('openai', 'gpt-4o');
    $context->httpStatus = 200;

    $request = $manager->recordSuccess($context, ['content' => 'Hello!'], ['prompt_tokens' => 10, 'completion_tokens' => 5]);

    expect($request->status_code)->toBe(200)
        ->and($request->prompt_tokens)->toBe(10)
        ->and($request->completion_tokens)->toBe(5)
        ->and($request->total_tokens)->toBe(15);
});

it('records the status code of a failed request', function () {
    $manager = app(Spectra::class);
    $context = $manager->startRequest('openai', 'gpt-4o');

    $request = $manager->recordFailure($context, new Exception('API Error'), 500);

    expect($request->status_code)->toBe(500);
});

it('records a track() call with its provider and the model display name', function () {
    SpectraFacade::track('anthropic', 'claude-sonnet-4-20250514', fn () => [
        'content' => 'Response',
        'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
    ]);

    $request = SpectraRequest::sole();

    expect($request->provider)->toBe('anthropic')
        ->and($request->model)->toBe('Claude Sonnet 4');
});

it('reads the duration of a binary TTS response and prices it by its characters', function () {
    $manager = app(Spectra::class);
    $context = $manager->startRequest('openai', 'tts-1', [
        'endpoint' => '/v1/audio/speech',
        'request_data' => ['model' => 'tts-1', 'input' => 'Hello world', 'response_format' => 'wav'],
    ]);
    $context->rawResponseBody = $this->generateSilentWav();

    $request = $manager->recordSuccess($context, []);

    // tts-1: 1500 cents per 1M characters × 11 characters = 0.0165¢
    expect($request->model_type)->toBe('tts')
        ->and($request->duration_seconds)->toEqualWithDelta(1.0, 0.1)
        ->and((float) $request->total_cost_in_cents)->toEqualWithDelta(0.0165, 0.000001);
});

it('reads the response id of an SDK response object through its toArray()', function () {
    $manager = app(Spectra::class);
    $context = $manager->startRequest('openai', 'gpt-4o');
    $response = new class
    {
        public function toArray(): array
        {
            return ['id' => 'chatcmpl-obj456', 'content' => 'Hello!'];
        }
    };

    $request = $manager->recordSuccess($context, $response, ['prompt_tokens' => 10, 'completion_tokens' => 5]);

    expect($request->response_id)->toBe('chatcmpl-obj456');
});

it('drops a trace id set with withTraceId() on clearGlobals()', function () {
    $manager = app(Spectra::class);
    $manager->withTraceId('my-trace-123');

    $manager->clearGlobals();

    expect($manager->startRequest('openai', 'gpt-4o')->traceId)->not->toBe('my-trace-123');
});

it('records tracked requests on the fake for its assertions', function () {
    SpectraFacade::fake();

    SpectraFacade::track('openai', 'gpt-4o', fn () => ['content' => 'Hello']);

    SpectraFacade::assertRequestCount(1);
    SpectraFacade::assertProviderUsed('openai');
    SpectraFacade::assertModelUsed('gpt-4o');
});

it('records a failed track() call on the fake', function () {
    SpectraFacade::fake();

    expect(fn () => SpectraFacade::track('openai', 'gpt-4o', fn () => throw new Exception('Test error')))
        ->toThrow(Exception::class, 'Test error');

    SpectraFacade::assertFailed();
});
