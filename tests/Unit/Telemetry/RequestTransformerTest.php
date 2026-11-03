<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Spectra\Models\SpectraRequest;
use Spectra\Support\RequestTransformer;

/**
 * RequestTransformer shapes a stored request for integrations: it flags
 * failures, starts spans at started_at (created_at for older rows), never
 * ends them before they start, and never exports the payloads or internal
 * fields.
 */
it('marks a failed request as is_failed', function () {
    $request = SpectraRequest::factory()->make(['model' => 'gpt-4o', 'status_code' => 500]);

    $data = (new RequestTransformer)->transform($request);

    expect($data['is_failed'])->toBeTrue();
});

it('ends a request without completed_at at its start', function () {
    $now = now();
    $request = SpectraRequest::factory()->make(['model' => 'gpt-4o', 'created_at' => $now, 'completed_at' => null]);

    $data = (new RequestTransformer)->transform($request);

    expect($data['started_at']->timestamp)->toBe($now->timestamp)
        ->and($data['completed_at']->timestamp)->toBe($now->timestamp);
});

it('leaves the payloads and internal fields out', function () {
    $request = SpectraRequest::factory()->make([
        'model' => 'gpt-4o',
        'request' => ['messages' => [['role' => 'user', 'content' => 'Hello']]],
        'response' => ['choices' => [['message' => ['content' => 'Hi']]]],
        'media_storage_path' => ['path/to/file.png'],
        'batch_id' => 'batch-123',
    ]);

    $data = (new RequestTransformer)->transform($request);

    expect($data)->not->toHaveKey('request')
        ->and($data)->not->toHaveKey('response')
        ->and($data)->not->toHaveKey('media_storage_path')
        ->and($data)->not->toHaveKey('batch_id')
        ->and($data)->not->toHaveKey('provider_display_name')
        ->and($data)->not->toHaveKey('formatted_created_at')
        ->and($data)->not->toHaveKey('formatted_expires_at')
        ->and($data)->not->toHaveKey('created_at_human')
        ->and($data)->not->toHaveKey('created_at');
});

it('starts spans at the precise start time and never ends them before it', function () {
    $request = SpectraRequest::factory()->create([
        'started_at' => Carbon::parse('2026-11-03 10:00:00.900'),
        'created_at' => Carbon::parse('2026-11-03 10:00:00'),
        // Stored to the second: the call really ended at 10:00:00.950.
        'completed_at' => Carbon::parse('2026-11-03 10:00:00'),
    ]);

    $data = app(RequestTransformer::class)->transform($request->fresh());

    expect($data['started_at']->format('H:i:s.v'))->toBe('10:00:00.900')
        ->and($data['completed_at']->format('H:i:s.v'))->toBe('10:00:00.900');
});

it('starts spans of rows saved before started_at existed at created_at', function () {
    $request = SpectraRequest::factory()->create([
        'started_at' => null,
        'created_at' => Carbon::parse('2026-11-03 10:00:00'),
        'completed_at' => Carbon::parse('2026-11-03 10:00:02'),
    ]);

    $data = app(RequestTransformer::class)->transform($request->fresh());

    expect($data['started_at']->format('H:i:s'))->toBe('10:00:00')
        ->and($data['completed_at']->format('H:i:s'))->toBe('10:00:02');
});
