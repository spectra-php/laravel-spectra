<?php

declare(strict_types=1);

use Spectra\Providers\Google\Handlers\ImageHandler;
use Spectra\Providers\Google\Handlers\VideoHandler;

/**
 * The Google provider picks a handler from the response shape alone when a
 * response is tracked without an endpoint (the feature tests cover the
 * shared generateContent endpoint).
 */
it('resolves the image handler from the response shape when the endpoint is empty', function () {
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

    $handler = $this->googleProvider()->resolveHandler('', $response);

    expect($handler)->toBeInstanceOf(ImageHandler::class);
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

    $handler = $this->googleProvider()->resolveHandler('', $response);

    expect($handler)->toBeInstanceOf(VideoHandler::class);
});
