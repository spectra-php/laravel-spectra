<?php

use Spectra\Data\Metrics;
use Spectra\Data\VideoMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Google\Handlers\VideoHandler;

function googleVideoHandler(): VideoHandler
{
    return new VideoHandler;
}

// --- Basics ---

it('google video handler returns video model type', function () {
    expect(googleVideoHandler()->modelType())->toBe(ModelType::Video);
});

// --- matchesEndpoint ---

it('google video handler matches predictLongRunning v1beta endpoint', function () {
    expect(googleVideoHandler()->matchesEndpoint('/v1beta/models/veo-3.1-generate-001:predictLongRunning'))->toBeTrue();
});

it('google video handler matches predictLongRunning v1 endpoint', function () {
    expect(googleVideoHandler()->matchesEndpoint('/v1/models/veo-2.0-generate-001:predictLongRunning'))->toBeTrue();
});

it('google video handler matches fetchPredictOperation v1beta endpoint', function () {
    expect(googleVideoHandler()->matchesEndpoint('/v1beta/models/veo-3.1-generate-001:fetchPredictOperation'))->toBeTrue();
});

it('google video handler matches fetchPredictOperation v1 endpoint', function () {
    expect(googleVideoHandler()->matchesEndpoint('/v1/models/veo-2.0-generate-001:fetchPredictOperation'))->toBeTrue();
});

it('google video handler does not match generateContent endpoint', function () {
    expect(googleVideoHandler()->matchesEndpoint('/v1beta/models/gemini-2.0-flash:generateContent'))->toBeFalse();
});

it('google video handler does not match embedContent endpoint', function () {
    expect(googleVideoHandler()->matchesEndpoint('/v1/models/gemini-embedding-001:embedContent'))->toBeFalse();
});

// --- shouldSkipResponse ---

it('skips in-progress veo operation', function () {
    $response = [
        'name' => 'operations/123',
        'done' => false,
    ];

    expect(googleVideoHandler()->shouldSkipResponse($response))->toBeTrue();
});

it('skips response when done field is absent', function () {
    $response = [
        'name' => 'operations/123',
    ];

    expect(googleVideoHandler()->shouldSkipResponse($response))->toBeTrue();
});

it('does not skip completed veo operation', function () {
    $response = [
        'name' => 'operations/123',
        'done' => true,
        'response' => [
            'generateVideoResponse' => [
                'generatedSamples' => [
                    ['video' => ['uri' => 'https://example.com/video.mp4']],
                ],
            ],
        ],
    ];

    expect(googleVideoHandler()->shouldSkipResponse($response))->toBeFalse();
});

// --- matchesResponse ---

it('google video handler matches completed veo response with generateVideoResponse', function () {
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

    expect(googleVideoHandler()->matchesResponse($response))->toBeTrue();
});

it('google video handler matches completed veo response with videos format', function () {
    $response = [
        'name' => 'operations/123',
        'done' => true,
        'response' => [
            'videos' => [
                ['gcsUri' => 'gs://bucket/video.mp4', 'mimeType' => 'video/mp4'],
            ],
        ],
    ];

    expect(googleVideoHandler()->matchesResponse($response))->toBeTrue();
});

it('google video handler matches in-progress veo operation', function () {
    $response = [
        'name' => 'projects/123/locations/us-central1/publishers/google/models/veo-3.1-generate-001/operations/456',
        'done' => false,
    ];

    expect(googleVideoHandler()->matchesResponse($response))->toBeTrue();
});

it('google video handler does not match text generation response', function () {
    $response = [
        'candidates' => [[
            'content' => ['parts' => [['text' => 'Hello']]],
            'finishReason' => 'STOP',
        ]],
        'usageMetadata' => ['promptTokenCount' => 10],
    ];

    expect(googleVideoHandler()->matchesResponse($response))->toBeFalse();
});

// --- extractMetrics ---

it('extracts video count from completed veo response with generateVideoResponse', function () {
    $response = [
        'response' => [
            'generateVideoResponse' => [
                'generatedSamples' => [
                    ['video' => ['uri' => 'https://example.com/video1.mp4']],
                    ['video' => ['uri' => 'https://example.com/video2.mp4']],
                ],
            ],
        ],
    ];

    $metrics = googleVideoHandler()->extractMetrics(
        ['parameters' => ['durationSeconds' => 8]],
        $response,
    );

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->video)->toBeInstanceOf(VideoMetrics::class);
    expect($metrics->video->count)->toBe(2);
    expect($metrics->video->durationSeconds)->toBe(16.0);
});

it('extracts video count from completed veo response with videos format', function () {
    $response = [
        'done' => true,
        'response' => [
            'videos' => [
                ['gcsUri' => 'gs://bucket/video1.mp4', 'mimeType' => 'video/mp4'],
            ],
        ],
    ];

    $metrics = googleVideoHandler()->extractMetrics(
        ['parameters' => ['durationSeconds' => 6]],
        $response,
    );

    expect($metrics->video->count)->toBe(1);
    expect($metrics->video->durationSeconds)->toBe(6.0);
});

it('extracts duration from request parameters', function () {
    $response = [
        'response' => [
            'generateVideoResponse' => [
                'generatedSamples' => [
                    ['video' => ['uri' => 'https://example.com/video.mp4']],
                ],
            ],
        ],
    ];

    $metrics = googleVideoHandler()->extractMetrics(
        ['parameters' => ['durationSeconds' => 6]],
        $response,
    );

    expect($metrics->video->count)->toBe(1);
    expect($metrics->video->durationSeconds)->toBe(6.0);
});

it('returns null duration when not in request', function () {
    $response = [
        'response' => [
            'generateVideoResponse' => [
                'generatedSamples' => [
                    ['video' => ['uri' => 'https://example.com/video.mp4']],
                ],
            ],
        ],
    ];

    $metrics = googleVideoHandler()->extractMetrics([], $response);

    expect($metrics->video->count)->toBe(1);
    expect($metrics->video->durationSeconds)->toBeNull();
});

it('returns zero count for in-progress response', function () {
    $response = [
        'name' => 'operations/123',
        'done' => false,
    ];

    $metrics = googleVideoHandler()->extractMetrics([], $response);

    expect($metrics->video->count)->toBe(0);
    expect($metrics->video->durationSeconds)->toBeNull();
});

// --- extractModel ---

it('extracts model version from veo response', function () {
    $response = [
        'modelVersion' => 'veo-3.1-generate-001',
        'response' => [
            'generateVideoResponse' => [
                'generatedSamples' => [
                    ['video' => ['uri' => 'https://example.com/video.mp4']],
                ],
            ],
        ],
    ];

    expect(googleVideoHandler()->extractModel($response))->toBe('veo-3.1-generate-001');
});

it('returns null model when not present in veo response', function () {
    expect(googleVideoHandler()->extractModel([]))->toBeNull();
});

// --- extractResponse ---

it('extracts video uris from completed response with generateVideoResponse', function () {
    $response = [
        'response' => [
            'generateVideoResponse' => [
                'generatedSamples' => [
                    ['video' => ['uri' => 'https://example.com/video1.mp4']],
                    ['video' => ['uri' => 'https://example.com/video2.mp4']],
                ],
            ],
        ],
    ];

    $result = googleVideoHandler()->extractResponse($response);

    expect($result)->toBe("https://example.com/video1.mp4\nhttps://example.com/video2.mp4");
});

it('extracts gcsUri from completed response with videos format', function () {
    $response = [
        'done' => true,
        'response' => [
            'videos' => [
                ['gcsUri' => 'gs://bucket/video1.mp4', 'mimeType' => 'video/mp4'],
                ['gcsUri' => 'gs://bucket/video2.mp4', 'mimeType' => 'video/mp4'],
            ],
        ],
    ];

    $result = googleVideoHandler()->extractResponse($response);

    expect($result)->toBe("gs://bucket/video1.mp4\ngs://bucket/video2.mp4");
});

it('returns null response for empty veo response', function () {
    expect(googleVideoHandler()->extractResponse([]))->toBeNull();
});

it('returns generated video placeholder when no uri', function () {
    $response = [
        'response' => [
            'generateVideoResponse' => [
                'generatedSamples' => [
                    ['video' => ['mimeType' => 'video/mp4']],
                ],
            ],
        ],
    ];

    expect(googleVideoHandler()->extractResponse($response))->toBe('[generated video]');
});

// --- extractFinishReason ---

it('returns COMPLETE for done veo operation', function () {
    $response = ['done' => true];

    expect(googleVideoHandler()->extractFinishReason($response))->toBe('COMPLETE');
});

it('returns PROCESSING for in-progress veo operation', function () {
    $response = ['done' => false];

    expect(googleVideoHandler()->extractFinishReason($response))->toBe('PROCESSING');
});

it('returns null finish reason when done field is absent', function () {
    expect(googleVideoHandler()->extractFinishReason([]))->toBeNull();
});

// --- extractModelFromRequest (from URL) ---

it('extracts veo model name from predictLongRunning endpoint', function () {
    expect(googleVideoHandler()->extractModelFromRequest([], '/v1beta/models/veo-3.1-generate-001:predictLongRunning'))
        ->toBe('veo-3.1-generate-001');
});

it('extracts veo model name from fetchPredictOperation endpoint', function () {
    expect(googleVideoHandler()->extractModelFromRequest([], '/v1/models/veo-3.1-generate-001:fetchPredictOperation'))
        ->toBe('veo-3.1-generate-001');
});

it('extracts veo 2 model name from endpoint', function () {
    expect(googleVideoHandler()->extractModelFromRequest([], '/v1/models/veo-2.0-generate-001:predictLongRunning'))
        ->toBe('veo-2.0-generate-001');
});
