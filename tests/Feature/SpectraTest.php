<?php

use Spectra\Facades\Spectra as SpectraFacade;
use Spectra\Models\SpectraRequest;
use Spectra\Spectra;

function generateSilentWavForSpectraTest(int $seconds = 1, int $sampleRate = 8000): string
{
    $channels = 1;
    $bitsPerSample = 16;
    $bytesPerSample = intdiv($bitsPerSample, 8);
    $dataSize = $seconds * $sampleRate * $channels * $bytesPerSample;
    $byteRate = $sampleRate * $channels * $bytesPerSample;
    $blockAlign = $channels * $bytesPerSample;

    return 'RIFF'
        .pack('V', 36 + $dataSize)
        .'WAVE'
        .'fmt '
        .pack('VvvVVvv', 16, 1, $channels, $sampleRate, $byteRate, $blockAlign, $bitsPerSample)
        .'data'
        .pack('V', $dataSize)
        .str_repeat("\x00", $dataSize);
}

it('can track an ai request', function () {
    $manager = app(Spectra::class);

    $context = $manager->startRequest('openai', 'gpt-4o', [
        'prompt' => 'Hello, world!',
    ]);

    expect($context->provider)->toBe('openai')
        ->and($context->model)->toBe('gpt-4o');
});

it('can record a successful request', function () {
    $manager = app(Spectra::class);

    $context = $manager->startRequest('openai', 'gpt-4o');
    $context->httpStatus = 200;

    $request = $manager->recordSuccess($context, [
        'content' => 'Hello!',
    ], [
        'prompt_tokens' => 10,
        'completion_tokens' => 5,
    ]);

    expect($request)->toBeInstanceOf(SpectraRequest::class)
        ->and($request->status_code)->toBe(200)
        ->and($request->prompt_tokens)->toBe(10)
        ->and($request->completion_tokens)->toBe(5)
        ->and($request->total_tokens)->toBe(15);
});

it('saves model_type when set on context', function () {
    $manager = app(Spectra::class);

    $context = $manager->startRequest('openai', 'gpt-4o');
    $context->httpStatus = 200;
    $context->modelType = 'text';

    $request = $manager->recordSuccess($context, [
        'content' => 'Hello!',
    ], [
        'prompt_tokens' => 10,
        'completion_tokens' => 5,
    ]);

    expect($request->model_type)->toBe('text');

    // Verify it persists in DB
    $fresh = SpectraRequest::find($request->id);
    expect($fresh->model_type)->toBe('text');
});

it('saves image model_type with billing metrics', function () {
    $manager = app(Spectra::class);

    $context = $manager->startRequest('openai', 'dall-e-3');
    $context->httpStatus = 200;
    $context->modelType = 'image';
    $context->imageCount = 2;

    $request = $manager->recordSuccess($context, [
        'data' => [['url' => 'https://example.com/img.png']],
    ], [
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
    ]);

    expect($request->model_type)->toBe('image')
        ->and($request->image_count)->toBe(2);
});

it('saves video model_type with billing metrics', function () {
    $manager = app(Spectra::class);

    $context = $manager->startRequest('openai', 'sora-2');
    $context->httpStatus = 200;
    $context->modelType = 'video';
    $context->videoCount = 1;

    $request = $manager->recordSuccess($context, [
        'data' => [['url' => 'https://example.com/video.mp4']],
    ], [
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
    ]);

    expect($request->model_type)->toBe('video')
        ->and($request->video_count)->toBe(1);
});

it('can record a failed request', function () {
    $manager = app(Spectra::class);

    $context = $manager->startRequest('openai', 'gpt-4o');

    $request = $manager->recordFailure(
        $context,
        new \Exception('API Error'),
        500
    );

    expect($request->status_code)->toBe(500);
});

it('can track using the track helper', function () {
    $result = SpectraFacade::track('anthropic', 'claude-sonnet-4-20250514', function ($context) {
        return [
            'content' => 'Response',
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
            ],
        ];
    });

    expect($result)->toBeArray();

    $request = SpectraRequest::latest()->first();
    expect($request->provider)->toBe('anthropic')
        ->and($request->model)->toBe('Claude Sonnet 4');
});

it('uses extracted binary tts duration for minute-based persisted cost', function () {
    $manager = app(Spectra::class);

    $context = $manager->startRequest('openai', 'tts-1', [
        'endpoint' => '/v1/audio/speech',
        'request_data' => [
            'model' => 'tts-1',
            'input' => 'Hello world',
            'response_format' => 'wav',
        ],
    ]);
    $context->rawResponseBody = generateSilentWavForSpectraTest();

    $request = $manager->recordSuccess($context, []);

    expect($request->model_type)->toBe('tts')
        ->and($request->duration_seconds)->toBeGreaterThan(0.9)->toBeLessThan(1.1)
        ->and($request->total_cost_in_cents)->toBeGreaterThan(0.0);
});

it('can use the fake for testing', function () {
    SpectraFacade::fake();

    SpectraFacade::track('openai', 'gpt-4o', function () {
        return ['content' => 'Hello'];
    });

    SpectraFacade::assertRequestCount(1);
    SpectraFacade::assertProviderUsed('openai');
    SpectraFacade::assertModelUsed('gpt-4o');
});

it('applies global trace id to all requests', function () {
    $manager = app(Spectra::class);

    $manager->withTraceId('my-trace-123');

    $context1 = $manager->startRequest('openai', 'gpt-4o');
    $context2 = $manager->startRequest('anthropic', 'claude-3-sonnet');

    expect($context1->traceId)->toBe('my-trace-123')
        ->and($context2->traceId)->toBe('my-trace-123');

    $manager->clearGlobals();

    $context3 = $manager->startRequest('openai', 'gpt-4o');
    expect($context3->traceId)->not->toBe('my-trace-123');
});

it('allows per-request trace id to override global trace id', function () {
    $manager = app(Spectra::class);

    $manager->withTraceId('global-trace');

    $context = $manager->startRequest('openai', 'gpt-4o', [
        'trace_id' => 'request-trace',
    ]);

    expect($context->traceId)->toBe('request-trace');

    $manager->clearGlobals();
});

it('extracts response_id from array responses', function () {
    $manager = app(Spectra::class);

    $context = $manager->startRequest('openai', 'gpt-4o');

    $request = $manager->recordSuccess($context, [
        'id' => 'chatcmpl-abc123',
        'content' => 'Hello!',
    ], [
        'prompt_tokens' => 10,
        'completion_tokens' => 5,
    ]);

    expect($request->response_id)->toBe('chatcmpl-abc123');
});

it('extracts response_id from object responses', function () {
    $manager = app(Spectra::class);

    $context = $manager->startRequest('openai', 'gpt-4o');

    $response = new class
    {
        public string $id = 'chatcmpl-obj456';

        public string $content = 'Hello!';

        public function toArray(): array
        {
            return ['id' => $this->id, 'content' => $this->content];
        }
    };

    $request = $manager->recordSuccess($context, $response, [
        'prompt_tokens' => 10,
        'completion_tokens' => 5,
    ]);

    expect($request->response_id)->toBe('chatcmpl-obj456');
});

it('tracks failed requests in fake', function () {
    SpectraFacade::fake();

    try {
        SpectraFacade::track('openai', 'gpt-4o', function () {
            throw new \Exception('Test error');
        });
    } catch (\Exception $e) {
        // Expected
    }

    SpectraFacade::assertFailed();
});

it('saves finish_reason when set on context', function () {
    $manager = app(Spectra::class);

    $context = $manager->startRequest('openai', 'gpt-4o');
    $context->httpStatus = 200;
    $context->finishReason = 'stop';

    $request = $manager->recordSuccess($context, [
        'content' => 'Hello!',
    ], [
        'prompt_tokens' => 10,
        'completion_tokens' => 5,
    ]);

    expect($request->finish_reason)->toBe('stop');

    $fresh = SpectraRequest::find($request->id);
    expect($fresh->finish_reason)->toBe('stop');
});

it('saves reasoning_tokens when set on context', function () {
    $manager = app(Spectra::class);

    $context = $manager->startRequest('openai', 'o3-mini');
    $context->httpStatus = 200;
    $context->reasoningTokens = 150;

    $request = $manager->recordSuccess($context, [
        'content' => 'Thinking...',
    ], [
        'prompt_tokens' => 50,
        'completion_tokens' => 20,
    ]);

    expect($request->reasoning_tokens)->toBe(150);

    $fresh = SpectraRequest::find($request->id);
    expect($fresh->reasoning_tokens)->toBe(150);
});

it('saves has_tool_calls when set on context', function () {
    $manager = app(Spectra::class);

    $context = $manager->startRequest('openai', 'gpt-4o');
    $context->httpStatus = 200;
    $context->hasToolCalls = true;
    $context->finishReason = 'tool_calls';

    $request = $manager->recordSuccess($context, [
        'content' => '',
        'choices' => [['message' => ['tool_calls' => [['type' => 'function']]]]],
    ], [
        'prompt_tokens' => 10,
        'completion_tokens' => 5,
    ]);

    expect($request->has_tool_calls)->toBeTrue()
        ->and($request->finish_reason)->toBe('tool_calls');

    $fresh = SpectraRequest::find($request->id);
    expect($fresh->has_tool_calls)->toBeTrue();
});

it('defaults has_tool_calls to false', function () {
    $manager = app(Spectra::class);

    $context = $manager->startRequest('openai', 'gpt-4o');
    $context->httpStatus = 200;

    $request = $manager->recordSuccess($context, [
        'content' => 'Hello!',
    ], [
        'prompt_tokens' => 10,
        'completion_tokens' => 5,
    ]);

    expect($request->has_tool_calls)->toBeFalse()
        ->and($request->reasoning_tokens)->toBe(0);
});
