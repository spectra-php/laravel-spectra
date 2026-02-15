<?php

use Illuminate\Support\Facades\Http;
use Spectra\Data\AudioMetrics;
use Spectra\Data\ImageMetrics;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Data\VideoMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\OpenAI\Handlers\EmbeddingHandler;
use Spectra\Providers\OpenAI\Handlers\ImageHandler;
use Spectra\Providers\OpenAI\Handlers\ResponsesImageHandler;
use Spectra\Providers\OpenAI\Handlers\SpeechHandler;
use Spectra\Providers\OpenAI\Handlers\TextHandler;
use Spectra\Providers\OpenAI\Handlers\TranscriptionHandler;
use Spectra\Providers\OpenAI\Handlers\VideoHandler;
use Spectra\Providers\OpenAI\OpenAI;

function openAiProvider(): OpenAI
{
    return new OpenAI;
}

it('extracts usage from completions api response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/completion.json'), true);

    $metrics = openAiProvider()->extractMetrics($response);

    expect($metrics->tokens->promptTokens)->toBe(19);
    expect($metrics->tokens->completionTokens)->toBe(10);
    expect($metrics->tokens->cachedTokens)->toBe(0);
});

it('extracts usage from responses api response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/response.json'), true);

    $metrics = openAiProvider()->extractMetrics($response);

    expect($metrics->tokens->promptTokens)->toBe(36);
    expect($metrics->tokens->completionTokens)->toBe(87);
    expect($metrics->tokens->cachedTokens)->toBe(0);
});

it('extracts content from completions api response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/completion.json'), true);

    $content = openAiProvider()->extractResponse($response);

    expect($content)->toBe('Hello! How can I assist you today?');
});

it('extracts content from responses api response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/response.json'), true);

    $content = openAiProvider()->extractResponse($response);

    expect($content)->toContain('unicorn named Lumina');
});

it('extracts model from completions api response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/completion.json'), true);

    $model = openAiProvider()->extractModel($response);

    expect($model)->toBe('gpt-4.1-2025-04-14');
});

it('extracts model from responses api response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/response.json'), true);

    $model = openAiProvider()->extractModel($response);

    expect($model)->toBe('gpt-4.1-2025-04-14');
});

it('detects completions api response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/completion.json'), true);

    expect(TextHandler::isCompletionsApi($response))->toBeTrue();
    expect(TextHandler::isResponsesApi($response))->toBeFalse();
});

it('detects responses api response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/response.json'), true);

    expect(TextHandler::isResponsesApi($response))->toBeTrue();
    expect(TextHandler::isCompletionsApi($response))->toBeFalse();
});

it('detects batch response', function () {
    $lines = file(__DIR__.'/../responses/openai/batch.jsonl', FILE_IGNORE_NEW_LINES);
    $response = json_decode($lines[0], true);

    expect(TextHandler::isBatch($response))->toBeTrue();
});

it('extracts metrics from batch response', function () {
    $lines = file(__DIR__.'/../responses/openai/batch.jsonl', FILE_IGNORE_NEW_LINES);
    $response = json_decode($lines[0], true);

    $data = TextHandler::unwrapBatch($response);
    $handler = new TextHandler;
    $metrics = $handler->extractMetrics([], $data);

    expect($metrics->tokens->promptTokens)->toBe(22);
    expect($metrics->tokens->completionTokens)->toBe(2);
});

it('extracts model from batch response', function () {
    $lines = file(__DIR__.'/../responses/openai/batch.jsonl', FILE_IGNORE_NEW_LINES);
    $response = json_decode($lines[0], true);

    $data = TextHandler::unwrapBatch($response);
    $model = (new TextHandler)->extractModel($data);

    expect($model)->toBe('gpt-4o');
});

it('extracts content from batch response', function () {
    $lines = file(__DIR__.'/../responses/openai/batch.jsonl', FILE_IGNORE_NEW_LINES);
    $response = json_decode($lines[0], true);

    $data = TextHandler::unwrapBatch($response);
    $content = (new TextHandler)->extractResponse($data);

    expect($content)->toBe('Hello.');
});

it('extracts finish reason from completions api', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/completion.json'), true);

    $finishReason = openAiProvider()->extractFinishReason($response);

    expect($finishReason)->toBe('stop');
});

it('extracts status from responses api', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/response.json'), true);

    $finishReason = openAiProvider()->extractFinishReason($response);

    expect($finishReason)->toBe('completed');
});

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

    $content = openAiProvider()->extractResponse($response);

    // Multiple choices are joined with newlines
    expect($content)->toBe("Response 1\nResponse 2\nResponse 3");
});

it('extracts content from single choice without extra newlines', function () {
    $response = [
        'object' => 'chat.completion',
        'model' => 'gpt-4o',
        'choices' => [
            ['index' => 0, 'message' => ['content' => 'Single response'], 'finish_reason' => 'stop'],
        ],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ];

    $content = openAiProvider()->extractResponse($response);

    expect($content)->toBe('Single response');
});

// --- Handler Resolution ---

it('resolves text handler for chat completions endpoint', function () {
    $handler = openAiProvider()->resolveHandler('/v1/chat/completions');

    expect($handler)->toBeInstanceOf(TextHandler::class);
});

it('resolves text handler for responses endpoint', function () {
    $handler = openAiProvider()->resolveHandler('/v1/responses');

    expect($handler)->toBeInstanceOf(TextHandler::class);
});

it('resolves image handler for image generations endpoint', function () {
    $handler = openAiProvider()->resolveHandler('/v1/images/generations');

    expect($handler)->toBeInstanceOf(ImageHandler::class);
});

it('resolves image handler for image edits endpoint', function () {
    $handler = openAiProvider()->resolveHandler('/v1/images/edits');

    expect($handler)->toBeInstanceOf(ImageHandler::class);
});

it('resolves image handler for image variations endpoint', function () {
    $handler = openAiProvider()->resolveHandler('/v1/images/variations');

    expect($handler)->toBeInstanceOf(ImageHandler::class);
});

it('resolves embedding handler for embeddings endpoint', function () {
    $handler = openAiProvider()->resolveHandler('/v1/embeddings');

    expect($handler)->toBeInstanceOf(EmbeddingHandler::class);
});

it('returns null for unknown endpoint', function () {
    $handler = openAiProvider()->resolveHandler('/v1/unknown');

    expect($handler)->toBeNull();
});

it('aggregates endpoints from all handlers', function () {
    $endpoints = openAiProvider()->getEndpoints();

    expect($endpoints)->toContain('/v1/images/generations');
    expect($endpoints)->toContain('/v1/images/edits');
    expect($endpoints)->toContain('/v1/images/variations');
    expect($endpoints)->toContain('/v1/embeddings');
    expect($endpoints)->toContain('/v1/chat/completions');
    expect($endpoints)->toContain('/v1/responses');
});

// --- Image Handler ---

it('extracts image count from dall-e response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/image_generation.json'), true);

    $handler = new ImageHandler;
    $metrics = $handler->extractMetrics(['prompt' => 'A cat'], $response);

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->image)->toBeInstanceOf(ImageMetrics::class);
    expect($metrics->image->count)->toBe(2);
});

it('extracts no tokens from dall-e response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/image_generation.json'), true);

    $handler = new ImageHandler;
    $metrics = $handler->extractMetrics(['prompt' => 'A cat'], $response);

    expect($metrics->tokens)->toBeNull();
});

it('extracts tokens from gpt-image response with usage', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/image_generation_with_usage.json'), true);

    $handler = new ImageHandler;
    $metrics = $handler->extractMetrics(['prompt' => 'A landscape'], $response);

    expect($metrics->tokens)->toBeInstanceOf(TokenMetrics::class);
    expect($metrics->tokens->promptTokens)->toBe(8);
    expect($metrics->tokens->completionTokens)->toBe(1433);
    expect($metrics->image)->toBeInstanceOf(ImageMetrics::class);
    expect($metrics->image->count)->toBe(1);
});

it('extracts image urls from dall-e response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/image_generation.json'), true);

    $handler = new ImageHandler;
    $content = $handler->extractResponse($response);

    expect($content)->toContain('example-image-1.png');
    expect($content)->toContain('example-image-2.png');
});

it('extracts base64 indicator from dall-e response', function () {
    $response = [
        'created' => 1700000000,
        'data' => [
            ['b64_json' => 'iVBORw0KGgoAAAANSUhEUg...'],
        ],
    ];

    $handler = new ImageHandler;
    $content = $handler->extractResponse($response);

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

    $handler = new ImageHandler;
    $stored = $handler->storeMedia('req_image_url_extension', [
        'data' => [
            ['url' => 'https://example.com/image'],
        ],
    ]);

    expect($stored)->toHaveCount(1);
    expect($stored[0])->toEndWith('.webp');
});

it('extracts image metrics via provider for image endpoint', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/image_generation.json'), true);

    $metrics = openAiProvider()->extractMetrics(
        $response,
        '/v1/images/generations',
        ['prompt' => 'A cat']
    );

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->image)->toBeInstanceOf(ImageMetrics::class);
    expect($metrics->image->count)->toBe(2);
});

it('returns tokens-only metrics for text endpoint', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/completion.json'), true);

    $metrics = openAiProvider()->extractMetrics(
        $response,
        '/v1/chat/completions',
        ['model' => 'gpt-4o']
    );

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->tokens)->toBeInstanceOf(TokenMetrics::class);
    expect($metrics->image)->toBeNull();
});

it('returns empty metrics for unknown endpoint', function () {
    $metrics = openAiProvider()->extractMetrics([], '/v1/unknown');

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->tokens)->toBeNull();
    expect($metrics->image)->toBeNull();
});

// --- Embedding Handler ---

it('extracts usage from embedding response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/embedding.json'), true);

    $handler = new EmbeddingHandler;
    $metrics = $handler->extractMetrics([], $response);

    expect($metrics->tokens->promptTokens)->toBe(5);
    expect($metrics->tokens->completionTokens)->toBe(0);
    expect($metrics->tokens->cachedTokens)->toBe(0);
});

it('extracts model from embedding response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/embedding.json'), true);

    $handler = new EmbeddingHandler;

    expect($handler->extractModel($response))->toBe('text-embedding-3-small');
});

it('extracts dimension info from embedding response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/embedding.json'), true);

    $handler = new EmbeddingHandler;
    $content = $handler->extractResponse($response);

    expect($content)->toBe('[embedding: 10 dimensions]');
});

it('returns tokens-only metrics for embedding response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/embedding.json'), true);

    $handler = new EmbeddingHandler;
    $metrics = $handler->extractMetrics(['model' => 'text-embedding-3-small'], $response);

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->tokens)->toBeInstanceOf(TokenMetrics::class);
    expect($metrics->image)->toBeNull();
});

// --- resolveModelType() ---

it('resolves text model type for chat completions endpoint', function () {
    expect(openAiProvider()->resolveModelType('/v1/chat/completions'))->toBe(ModelType::Text);
});

it('resolves text model type for responses endpoint', function () {
    expect(openAiProvider()->resolveModelType('/v1/responses'))->toBe(ModelType::Text);
});

it('resolves image model type for image generations endpoint', function () {
    expect(openAiProvider()->resolveModelType('/v1/images/generations'))->toBe(ModelType::Image);
});

it('resolves image model type for image edits endpoint', function () {
    expect(openAiProvider()->resolveModelType('/v1/images/edits'))->toBe(ModelType::Image);
});

it('resolves embedding model type for embeddings endpoint', function () {
    expect(openAiProvider()->resolveModelType('/v1/embeddings'))->toBe(ModelType::Embedding);
});

it('returns null model type for unknown endpoint', function () {
    expect(openAiProvider()->resolveModelType('/v1/unknown'))->toBeNull();
});

// --- Handler modelType() ---

it('text handler returns text model type', function () {
    $handler = new TextHandler;
    expect($handler->modelType())->toBe(ModelType::Text);
});

it('image handler returns image model type', function () {
    $handler = new ImageHandler;
    expect($handler->modelType())->toBe(ModelType::Image);
});

it('embedding handler returns embedding model type', function () {
    $handler = new EmbeddingHandler;
    expect($handler->modelType())->toBe(ModelType::Embedding);
});

// --- Transcription Handler ---

it('resolves transcription handler for transcriptions endpoint', function () {
    $handler = openAiProvider()->resolveHandler('/v1/audio/transcriptions');

    expect($handler)->toBeInstanceOf(TranscriptionHandler::class);
});

it('resolves transcription handler for translations endpoint', function () {
    $handler = openAiProvider()->resolveHandler('/v1/audio/translations');

    expect($handler)->toBeInstanceOf(TranscriptionHandler::class);
});

it('transcription handler returns stt model type', function () {
    $handler = new TranscriptionHandler;

    expect($handler->modelType())->toBe(ModelType::Stt);
});

it('extracts text from transcription response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/transcription.json'), true);

    $handler = new TranscriptionHandler;

    expect($handler->extractResponse($response))->toBe('Hello world');
});

it('extracts duration from verbose transcription response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/transcription_verbose.json'), true);

    $handler = new TranscriptionHandler;
    $metrics = $handler->extractMetrics(['model' => 'whisper-1'], $response);

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->audio)->toBeInstanceOf(AudioMetrics::class);
    expect($metrics->audio->durationSeconds)->toBe(3.45);
});

it('returns null audio duration when transcription has no duration', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/transcription.json'), true);

    $handler = new TranscriptionHandler;
    $metrics = $handler->extractMetrics(['model' => 'whisper-1'], $response);

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->audio->durationSeconds)->toBeNull();
});

it('extracts model from verbose transcription response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/transcription_verbose.json'), true);

    $handler = new TranscriptionHandler;

    expect($handler->extractModel($response))->toBe('whisper-1');
});

it('extracts usage tokens from gpt-4o-transcribe response', function () {
    $response = [
        'text' => 'Hello world',
        'usage' => [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
        ],
    ];

    $handler = new TranscriptionHandler;
    $metrics = $handler->extractMetrics([], $response);

    expect($metrics->tokens->promptTokens)->toBe(100);
    expect($metrics->tokens->completionTokens)->toBe(50);
    expect($metrics->tokens->cachedTokens)->toBe(0);
});

it('extracts duration from duration-based usage format', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/transcription_duration_usage.json'), true);

    $handler = new TranscriptionHandler;
    $metrics = $handler->extractMetrics(['model' => 'whisper-1'], $response);

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->audio)->toBeInstanceOf(AudioMetrics::class);
    expect($metrics->audio->durationSeconds)->toBe(11.0);
    expect($metrics->tokens->promptTokens)->toBe(0);
    expect($metrics->tokens->completionTokens)->toBe(0);
});

it('extracts input_tokens and output_tokens from transcription usage', function () {
    $response = [
        'text' => 'Hello world',
        'usage' => [
            'type' => 'tokens',
            'total_tokens' => 129,
            'input_tokens' => 107,
            'input_token_details' => [
                'text_tokens' => 0,
                'audio_tokens' => 107,
            ],
            'output_tokens' => 22,
        ],
    ];

    $handler = new TranscriptionHandler;
    $metrics = $handler->extractMetrics([], $response);

    expect($metrics->tokens->promptTokens)->toBe(107);
    expect($metrics->tokens->completionTokens)->toBe(22);
});

it('returns zero tokens for whisper transcription response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/transcription.json'), true);

    $handler = new TranscriptionHandler;
    $metrics = $handler->extractMetrics([], $response);

    expect($metrics->tokens->promptTokens)->toBe(0);
    expect($metrics->tokens->completionTokens)->toBe(0);
    expect($metrics->tokens->cachedTokens)->toBe(0);
});

// --- Speech Handler ---

it('resolves speech handler for speech endpoint', function () {
    $handler = openAiProvider()->resolveHandler('/v1/audio/speech');

    expect($handler)->toBeInstanceOf(SpeechHandler::class);
});

it('speech handler returns tts model type', function () {
    $handler = new SpeechHandler;

    expect($handler->modelType())->toBe(ModelType::Tts);
});

it('returns no tokens for speech response', function () {
    $handler = new SpeechHandler;
    $metrics = $handler->extractMetrics([], []);

    expect($metrics->tokens)->toBeNull();
});

it('extracts input characters from speech request', function () {
    $handler = new SpeechHandler;
    $metrics = $handler->extractMetrics(
        ['model' => 'tts-1', 'input' => 'Hello world'],
        []
    );

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->audio)->toBeInstanceOf(AudioMetrics::class);
    expect($metrics->audio->inputCharacters)->toBe(11);
});

it('returns null input characters when speech has no input', function () {
    $handler = new SpeechHandler;
    $metrics = $handler->extractMetrics(['model' => 'tts-1'], []);

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->audio->inputCharacters)->toBeNull();
});

it('returns audio placeholder for speech response', function () {
    $handler = new SpeechHandler;

    expect($handler->extractResponse([]))->toBe('[audio]');
});

it('returns null model for speech response', function () {
    $handler = new SpeechHandler;

    expect($handler->extractModel([]))->toBeNull();
});

// --- Audio Endpoints in Provider ---

it('aggregates audio endpoints from all handlers', function () {
    $endpoints = openAiProvider()->getEndpoints();

    expect($endpoints)->toContain('/v1/audio/transcriptions');
    expect($endpoints)->toContain('/v1/audio/translations');
    expect($endpoints)->toContain('/v1/audio/speech');
});

it('resolves stt model type for transcriptions endpoint', function () {
    expect(openAiProvider()->resolveModelType('/v1/audio/transcriptions'))->toBe(ModelType::Stt);
});

it('resolves tts model type for speech endpoint', function () {
    expect(openAiProvider()->resolveModelType('/v1/audio/speech'))->toBe(ModelType::Tts);
});

it('extracts audio metrics via provider for transcription endpoint', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/transcription_verbose.json'), true);

    $metrics = openAiProvider()->extractMetrics(
        $response,
        '/v1/audio/transcriptions',
        ['model' => 'whisper-1']
    );

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->audio)->toBeInstanceOf(AudioMetrics::class);
    expect($metrics->audio->durationSeconds)->toBe(3.45);
});

it('extracts audio metrics via provider for speech endpoint', function () {
    $metrics = openAiProvider()->extractMetrics(
        [],
        '/v1/audio/speech',
        ['model' => 'tts-1', 'input' => 'Hello world']
    );

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->audio)->toBeInstanceOf(AudioMetrics::class);
    expect($metrics->audio->inputCharacters)->toBe(11);
});

// --- Video Handler ---

it('resolves video handler for /v1/videos endpoint', function () {
    $handler = openAiProvider()->resolveHandler('/v1/videos');

    expect($handler)->toBeInstanceOf(VideoHandler::class);
});

it('resolves video handler for /v1/videos/{id} endpoint', function () {
    $handler = openAiProvider()->resolveHandler('/v1/videos/video_abc123');

    expect($handler)->toBeInstanceOf(VideoHandler::class);
});

it('video handler returns video model type via provider', function () {
    expect(openAiProvider()->resolveModelType('/v1/videos'))->toBe(ModelType::Video);
});

it('aggregates video endpoints from all handlers', function () {
    $endpoints = openAiProvider()->getEndpoints();

    expect($endpoints)->toContain('/v1/videos');
    expect($endpoints)->toContain('/v1/videos/{id}');
});

it('extracts video metrics via provider for video endpoint', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/video_completed.json'), true);

    $metrics = openAiProvider()->extractMetrics(
        $response,
        '/v1/videos/video_abc123',
        ['prompt' => 'A futuristic city']
    );

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->video)->toBeInstanceOf(VideoMetrics::class);
    expect($metrics->video->count)->toBe(1);
    expect($metrics->video->durationSeconds)->toBe(10.0);
});

// --- Responses Image Handler ---

it('resolves responses image handler for responses endpoint with image generation response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/responses_image_generation.json'), true);

    $handler = openAiProvider()->resolveHandler('/v1/responses', $response);

    expect($handler)->toBeInstanceOf(ResponsesImageHandler::class);
});

it('resolves text handler for responses endpoint without response data', function () {
    $handler = openAiProvider()->resolveHandler('/v1/responses');

    expect($handler)->toBeInstanceOf(TextHandler::class);
});

it('resolves text handler for responses endpoint with text-only response data', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/response.json'), true);

    $handler = openAiProvider()->resolveHandler('/v1/responses', $response);

    expect($handler)->toBeInstanceOf(TextHandler::class);
});

it('responses image handler extracts both token and image metrics', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/responses_image_generation.json'), true);

    $handler = new ResponsesImageHandler;
    $metrics = $handler->extractMetrics([], $response);

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->tokens)->toBeInstanceOf(TokenMetrics::class);
    expect($metrics->tokens->promptTokens)->toBe(50);
    expect($metrics->tokens->completionTokens)->toBe(120);
    expect($metrics->tokens->cachedTokens)->toBe(0);
    expect($metrics->image)->toBeInstanceOf(ImageMetrics::class);
    expect($metrics->image->count)->toBe(1);
});

it('responses image handler extracts image count for multiple images', function () {
    $response = [
        'object' => 'response',
        'model' => 'gpt-4o',
        'status' => 'completed',
        'output' => [
            ['type' => 'image_generation_call', 'id' => 'ig_1', 'result' => 'base64data1'],
            ['type' => 'image_generation_call', 'id' => 'ig_2', 'result' => 'base64data2'],
            ['type' => 'image_generation_call', 'id' => 'ig_3', 'result' => 'base64data3'],
        ],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
    ];

    $handler = new ResponsesImageHandler;
    $metrics = $handler->extractMetrics([], $response);

    expect($metrics->image->count)->toBe(3);
});

it('responses image handler extracts model', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/responses_image_generation.json'), true);

    $handler = new ResponsesImageHandler;

    expect($handler->extractModel($response))->toBe('gpt-4o-2024-08-06');
});

it('responses image handler extracts finish reason', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/responses_image_generation.json'), true);

    $handler = new ResponsesImageHandler;

    expect($handler->extractFinishReason($response))->toBe('completed');
});

it('responses image handler extracts response with text content', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/responses_image_generation.json'), true);

    $handler = new ResponsesImageHandler;
    $content = $handler->extractResponse($response);

    expect($content)->toContain('[base64 image data]');
    expect($content)->toContain('Here is the image you requested.');
});

it('responses image handler matchesResponse returns false for text-only responses', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/response.json'), true);

    $handler = new ResponsesImageHandler;

    expect($handler->matchesResponse($response))->toBeFalse();
});

it('responses image handler matchesResponse returns true for image generation responses', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/responses_image_generation.json'), true);

    $handler = new ResponsesImageHandler;

    expect($handler->matchesResponse($response))->toBeTrue();
});

it('responses image handler returns image model type', function () {
    $handler = new ResponsesImageHandler;

    expect($handler->modelType())->toBe(ModelType::Image);
});

it('resolves text model type for responses endpoint without response data', function () {
    expect(openAiProvider()->resolveModelType('/v1/responses'))->toBe(ModelType::Text);
});

it('extracts image metrics via provider for responses endpoint with image generation', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/openai/responses_image_generation.json'), true);

    $metrics = openAiProvider()->extractMetrics($response, '/v1/responses', []);

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->image)->toBeInstanceOf(ImageMetrics::class);
    expect($metrics->image->count)->toBe(1);
    expect($metrics->tokens)->toBeInstanceOf(TokenMetrics::class);
    expect($metrics->tokens->promptTokens)->toBe(50);
});
