<?php

use Spectra\Providers\Google\Google;
use Spectra\Providers\Google\Handlers\GenerateContentHandler;
use Spectra\Providers\Google\Handlers\ImageHandler;
use Spectra\Providers\Google\Handlers\TtsHandler;
use Spectra\Providers\Google\Handlers\VideoHandler;

function googleProvider(): Google
{
    return new Google;
}

it('resolves generate content handler for text responses on shared endpoint', function () {
    $response = [
        'candidates' => [[
            'content' => ['parts' => [['text' => 'Hello']]],
            'finishReason' => 'STOP',
        ]],
        'usageMetadata' => ['promptTokenCount' => 10],
    ];

    $handler = googleProvider()->resolveHandler('/v1/models/gemini-2.0-flash:generateContent', $response);

    expect($handler)->toBeInstanceOf(GenerateContentHandler::class);
});

it('resolves image handler for inline image responses on shared endpoint', function () {
    $response = [
        'candidates' => [[
            'content' => ['parts' => [[
                'inlineData' => [
                    'mimeType' => 'image/png',
                    'data' => base64_encode('fake-image'),
                ],
            ]]],
        ]],
    ];

    $handler = googleProvider()->resolveHandler('/v1/models/gemini-2.0-flash:generateContent', $response);

    expect($handler)->toBeInstanceOf(ImageHandler::class);
});

it('resolves tts handler for inline audio responses on shared endpoint', function () {
    $response = [
        'candidates' => [[
            'content' => ['parts' => [[
                'inlineData' => [
                    'mimeType' => 'audio/mpeg',
                    'data' => base64_encode('fake-audio'),
                ],
            ]]],
        ]],
    ];

    $handler = googleProvider()->resolveHandler('/v1/models/gemini-2.0-flash:generateContent', $response);

    expect($handler)->toBeInstanceOf(TtsHandler::class);
});

it('can resolve google handler by response shape when endpoint is unavailable', function () {
    $response = [
        'candidates' => [[
            'content' => ['parts' => [[
                'inlineData' => [
                    'mimeType' => 'image/png',
                    'data' => base64_encode('fake-image'),
                ],
            ]]],
        ]],
    ];

    $handler = googleProvider()->resolveHandler('', $response);

    expect($handler)->toBeInstanceOf(ImageHandler::class);
});

it('resolves video handler for predictLongRunning endpoint', function () {
    $response = [
        'name' => 'operations/123',
        'done' => true,
        'response' => [
            'generateVideoResponse' => [
                'generatedSamples' => [
                    ['video' => ['uri' => 'https://example.com/video.mp4', 'mimeType' => 'video/mp4']],
                ],
            ],
        ],
    ];

    $handler = googleProvider()->resolveHandler('/v1beta/models/veo-3.1-generate-001:predictLongRunning', $response);

    expect($handler)->toBeInstanceOf(VideoHandler::class);
});

it('resolves video handler by response shape for veo response', function () {
    $response = [
        'response' => [
            'generateVideoResponse' => [
                'generatedSamples' => [
                    ['video' => ['uri' => 'https://example.com/video.mp4']],
                ],
            ],
        ],
    ];

    $handler = googleProvider()->resolveHandler('', $response);

    expect($handler)->toBeInstanceOf(VideoHandler::class);
});
