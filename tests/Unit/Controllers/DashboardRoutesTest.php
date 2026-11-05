<?php

declare(strict_types=1);

/**
 * Malformed ids on the dashboard API answer 404 before any query runs (on
 * PostgreSQL a non-UUID id against a uuid column is an error, not a miss),
 * and the SPA catch-all never swallows an API URL.
 */

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spectra\Models\SpectraRequest;

beforeEach(fn () => Gate::define('viewSpectra', fn ($user = null) => true));

it('answers 404 for a request id that is not a UUID', function (string $uri) {
    $this->get($uri)->assertNotFound();
})->with([
    'details' => '/spectra/api/requests/not-a-uuid',
    'video' => '/spectra/api/requests/not-a-uuid/video',
    'audio' => '/spectra/api/requests/not-a-uuid/audio',
    'image' => '/spectra/api/requests/not-a-uuid/images/0',
]);

it('answers 404 for an image index that is not a number', function () {
    $request = SpectraRequest::factory()->create(['model_type' => 'image']);

    $this->get("/spectra/api/requests/{$request->id}/images/first")->assertNotFound();
});

it('serves a request by its UUID', function () {
    $request = SpectraRequest::factory()->create();

    $this->getJson("/spectra/api/requests/{$request->id}")->assertOk()->assertJsonPath('id', $request->id);
});

it('serves the dashboard SPA for its own screens', function () {
    $this->get('/spectra/requests')->assertOk();
});

it('serves tts audio inline, and as a download when asked', function () {
    Storage::fake('local');
    Storage::disk('local')->put('spectra/req-3/0.wav', 'wav-bytes');
    $request = SpectraRequest::factory()->create(['model_type' => 'tts', 'media_storage_path' => ['spectra/req-3/0.wav']]);

    $inline = $this->get("/spectra/api/requests/{$request->id}/audio")->assertOk();
    $download = $this->get("/spectra/api/requests/{$request->id}/audio/download")->assertOk();

    expect($inline->headers->get('Content-Type'))->toBe('audio/wav')
        ->and($inline->headers->has('Content-Disposition'))->toBeFalse()
        ->and($inline->getContent())->toBe('wav-bytes')
        ->and($download->headers->get('Content-Disposition'))->toStartWith('attachment; filename=');
});
