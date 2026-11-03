<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Spectra\Providers\OpenAI\Handlers\BackgroundResponseHandler;
use Spectra\Providers\OpenAI\Handlers\ImageHandler;
use Spectra\Providers\OpenAI\Handlers\ResponsesImageHandler;
use Spectra\Providers\OpenAI\Handlers\TextHandler;
use Spectra\Providers\OpenAI\Handlers\TranscriptionHandler;
use Spectra\Providers\OpenAI\OpenAI;
use Spectra\Providers\OpenAI\OpenAiUsage;

/**
 * The OpenAI provider: specialist handler resolution on /v1/responses,
 * background polling routes, service tiers, usage normalisation, and the
 * image and transcription branches the feature tests don't reach.
 */
it('extracts content from multiple choices', function () {
    $response = [
        'object' => 'chat.completion',
        'model' => 'gpt-4o',
        'choices' => [
            ['index' => 0, 'message' => ['content' => 'Response 1'], 'finish_reason' => 'stop'],
            ['index' => 1, 'message' => ['content' => 'Response 2'], 'finish_reason' => 'stop'],
            ['index' => 2, 'message' => ['content' => 'Response 3'], 'finish_reason' => 'stop'],
        ],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 30],
    ];

    $content = $this->openAiProvider()->extractResponse($response);

    expect($content)->toBe("Response 1\nResponse 2\nResponse 3");
});

it('extracts image urls from dall-e response', function () {
    $response = $this->loadMockResponse('openai/image_generation.json');

    $content = (new ImageHandler)->extractResponse($response);

    expect($content)->toContain('example-image-1.png')
        ->and($content)->toContain('example-image-2.png');
});

it('extracts base64 indicator from dall-e response', function () {
    $response = [
        'created' => 1700000000,
        'data' => [
            ['b64_json' => 'iVBORw0KGgoAAAANSUhEUg...'],
        ],
    ];

    $content = (new ImageHandler)->extractResponse($response);

    expect($content)->toBe('[base64 image data]');
});

it('skips invalid base64 images during media storage', function () {
    $handler = new ImageHandler;

    $stored = $handler->storeMedia('req_invalid_b64', [
        'data' => [
            ['b64_json' => '@@@not-base64@@@'],
        ],
    ]);

    expect($stored)->toBe([]);
});

it('uses response content type when storing image urls', function () {
    Http::fake([
        'https://example.com/image' => Http::response('fake-image-binary', 200, [
            'Content-Type' => 'image/webp',
        ]),
    ]);

    $stored = (new ImageHandler)->storeMedia('req_image_url_extension', [
        'data' => [
            ['url' => 'https://example.com/image'],
        ],
    ]);

    expect($stored)->toHaveCount(1)
        ->and($stored[0])->toEndWith('.webp');
});

it('extracts no tokens from dall-e response', function () {
    $response = $this->loadMockResponse('openai/image_generation.json');

    $metrics = (new ImageHandler)->extractMetrics(['prompt' => 'A cat'], $response);

    expect($metrics->tokens)->toBeNull();
});

it('returns empty metrics for unknown endpoint', function () {
    $metrics = $this->openAiProvider()->extractMetrics([], '/v1/unknown');

    expect($metrics->tokens)->toBeNull()
        ->and($metrics->image)->toBeNull();
});

it('extracts duration from verbose transcription response', function () {
    $response = $this->loadMockResponse('openai/transcription_verbose.json');

    $metrics = (new TranscriptionHandler)->extractMetrics(['model' => 'whisper-1'], $response);

    expect($metrics->audio->durationSeconds)->toBe(3.45);
});

it('extracts usage tokens from gpt-4o-transcribe response', function () {
    $response = [
        'text' => 'Hello world',
        'usage' => [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
        ],
    ];

    $metrics = (new TranscriptionHandler)->extractMetrics([], $response);

    expect($metrics->tokens->promptTokens)->toBe(100)
        ->and($metrics->tokens->completionTokens)->toBe(50);
});

it('resolves responses image handler for responses endpoint with image generation response', function () {
    $response = $this->loadMockResponse('openai/responses_image_generation.json');

    $handler = $this->openAiProvider()->resolveHandler('/v1/responses', $response);

    expect($handler)->toBeInstanceOf(ResponsesImageHandler::class);
});

it('resolves text handler for responses endpoint with text-only response data', function () {
    $response = $this->loadMockResponse('openai/response.json');

    $handler = $this->openAiProvider()->resolveHandler('/v1/responses', $response);

    expect($handler)->toBeInstanceOf(TextHandler::class);
});

it('responses image handler extracts both token and image metrics', function () {
    $response = $this->loadMockResponse('openai/responses_image_generation.json');

    $metrics = (new ResponsesImageHandler)->extractMetrics([], $response);

    expect($metrics->tokens->promptTokens)->toBe(50)
        ->and($metrics->tokens->completionTokens)->toBe(120)
        ->and($metrics->image->count)->toBe(1);
});

it('responses image handler extracts response with text content', function () {
    $response = $this->loadMockResponse('openai/responses_image_generation.json');

    $content = (new ResponsesImageHandler)->extractResponse($response);

    expect($content)->toContain('[base64 image data]')
        ->and($content)->toContain('Here is the image you requested.');
});

it('maps openai service tiers to pricing tiers', function (?string $serviceTier, ?string $expected) {
    expect(OpenAI::pricingTierFor($serviceTier))->toBe($expected);
})->with([
    'scale bills at standard rates' => ['scale', 'standard'],
    'flex' => ['flex', 'flex'],
    'fast is the renamed priority' => ['fast', 'priority'],
    'ultrafast' => ['ultrafast', 'ultrafast'],
    'batch' => ['batch', 'batch'],
    'unknown' => ['turbo', null],
    'missing' => [null, null],
]);

it('reads the hd image tier from the request quality', function () {
    $provider = $this->openAiProvider();

    expect($provider->extractPricingTierFromRequest(['model' => 'dall-e-3', 'quality' => 'hd']))->toBe('hd')
        ->and($provider->extractPricingTierFromRequest(['model' => 'gpt-image-1', 'quality' => 'high']))->toBeNull()
        ->and($provider->extractPricingTierFromRequest(['service_tier' => 'fast']))->toBe('priority');
});

it('counts audio and image input as uncached tokens', function () {
    $tokens = OpenAiUsage::fromResponses([
        'input_tokens' => 1000,
        'input_tokens_details' => [
            'cached_tokens' => 300,
            'audio_tokens' => 400,
            'image_tokens' => 200,
            'cached_tokens_details' => ['audio_tokens' => 100, 'image_tokens' => 50],
        ],
        'output_tokens' => 80,
        'output_tokens_details' => ['reasoning_tokens' => 30, 'audio_tokens' => 40],
    ]);

    expect($tokens->promptTokens)->toBe(1000)
        ->and($tokens->cachedTokens)->toBe(300)
        ->and($tokens->audioInputTokens)->toBe(300)
        ->and($tokens->imageInputTokens)->toBe(150)
        ->and($tokens->completionTokens)->toBe(80)
        ->and($tokens->reasoningTokens)->toBe(30)
        ->and($tokens->audioOutputTokens)->toBe(40);
});

it('routes background polls and cancels to the background handler', function () {
    $provider = $this->openAiProvider();

    expect($provider->resolveHandler('/v1/responses/resp_abc123'))->toBeInstanceOf(BackgroundResponseHandler::class)
        ->and($provider->resolveHandler('/v1/responses/resp_abc123/cancel'))->toBeInstanceOf(BackgroundResponseHandler::class)
        ->and($provider->resolveHandler('/v1/responses/compact'))->toBeInstanceOf(TextHandler::class)
        ->and($provider->resolveHandler('/v1/responses/compact'))->not->toBeInstanceOf(BackgroundResponseHandler::class)
        ->and($provider->resolveHandler('/v1/responses/resp_abc123/input_items'))->toBeNull();
});

it('records only finished background responses', function () {
    $handler = new BackgroundResponseHandler;

    expect($handler->shouldSkipResponse(['object' => 'response', 'background' => true, 'status' => 'in_progress']))->toBeTrue()
        ->and($handler->shouldSkipResponse(['object' => 'response', 'background' => true, 'status' => 'completed']))->toBeFalse()
        ->and($handler->shouldSkipResponse(['object' => 'response', 'background' => true, 'status' => 'cancelled']))->toBeFalse()
        ->and($handler->shouldSkipResponse(['object' => 'response', 'background' => false, 'status' => 'completed']))->toBeTrue()
        ->and($handler->shouldSkipResponse(['object' => 'response', 'deleted' => true]))->toBeTrue()
        ->and($handler->shouldSkipResponse(['object' => 'response.input_tokens', 'input_tokens' => 11]))->toBeTrue();
});

it('never claims a response by shape for background polling', function () {
    $response = $this->loadMockResponse('openai/response.json');

    expect($this->openAiProvider()->resolveHandler('', $response))->toBeInstanceOf(TextHandler::class)
        ->and($this->openAiProvider()->resolveHandler('', $response))->not->toBeInstanceOf(BackgroundResponseHandler::class);
});
