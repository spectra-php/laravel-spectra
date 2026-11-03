<?php

declare(strict_types=1);

/**
 * The Google Veo video handler: the operation shapes it claims, and the
 * video count and billed seconds it reads from both finished-operation
 * formats (the feature tests cover the Gemini API job end to end).
 */
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

    expect($this->googleVideoHandler()->matchesResponse($response))->toBeTrue();
});

it('google video handler matches in-progress veo operation', function () {
    $response = [
        'name' => 'projects/123/locations/us-central1/publishers/google/models/veo-3.1-generate-001/operations/456',
        'done' => false,
    ];

    expect($this->googleVideoHandler()->matchesResponse($response))->toBeTrue();
});

it('google video handler does not match text generation response', function () {
    $response = [
        'candidates' => [[
            'content' => ['parts' => [['text' => 'Hello']]],
            'finishReason' => 'STOP',
        ]],
        'usageMetadata' => ['promptTokenCount' => 10],
    ];

    expect($this->googleVideoHandler()->matchesResponse($response))->toBeFalse();
});

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

    $metrics = $this->googleVideoHandler()->extractMetrics(
        ['parameters' => ['durationSeconds' => 8]],
        $response,
    );

    expect($metrics->video->count)->toBe(2)
        ->and($metrics->video->durationSeconds)->toBe(16.0);
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

    $metrics = $this->googleVideoHandler()->extractMetrics(
        ['parameters' => ['durationSeconds' => 6]],
        $response,
    );

    expect($metrics->video->count)->toBe(1)
        ->and($metrics->video->durationSeconds)->toBe(6.0);
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

    $metrics = $this->googleVideoHandler()->extractMetrics([], $response);

    expect($metrics->video->count)->toBe(1)
        ->and($metrics->video->durationSeconds)->toBeNull();
});
