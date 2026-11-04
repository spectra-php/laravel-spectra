<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spectra\Http\Controllers\Api\DownloadVideoController;
use Spectra\Http\Controllers\Api\RequestsController;
use Spectra\Http\Controllers\Api\ServeAudioController;
use Spectra\Http\Controllers\Api\ServeImageController;
use Spectra\Http\Controllers\Api\ShowRequestController;
use Spectra\Http\Controllers\Api\TagsController;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraTag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Dashboard endpoints that serve stored media, request details, trace
 * totals and tags.
 */
it('serves a persisted image with the stored mime type', function () {
    Storage::fake('local');
    Storage::disk('local')->put('spectra/req-1/0.webp', 'webp-bytes');

    $request = SpectraRequest::factory()->create([
        'model_type' => 'image',
        'media_storage_path' => ['spectra/req-1/0.webp'],
    ]);

    $response = app()->call([app(ServeImageController::class), '__invoke'], [
        'id' => $request->id,
        'index' => 0,
    ]);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toBe('image/webp')
        ->and($response->getContent())->toBe('webp-bytes');
});

it('rejects image requests for non-image records', function () {
    $request = SpectraRequest::factory()->create(['model_type' => 'text']);

    app()->call([app(ServeImageController::class), '__invoke'], [
        'id' => $request->id,
        'index' => 0,
    ]);
})->throws(NotFoundHttpException::class);

it('streams persisted videos with the real extension and mime type', function () {
    Storage::fake('local');
    Storage::disk('local')->put('spectra/req-2/0.webm', 'webm-bytes');

    $request = SpectraRequest::factory()->create([
        'model_type' => 'video',
        'media_storage_path' => ['spectra/req-2/0.webm'],
    ]);

    $response = app()->call([app(DownloadVideoController::class), '__invoke'], [
        'id' => $request->id,
    ]);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toBe('video/webm')
        ->and($response->headers->get('Content-Disposition'))->toContain('.webm');
});

it('exposes request and response payloads on the details endpoint', function () {
    $request = SpectraRequest::factory()->create([
        'model_type' => 'text',
        'request' => ['model' => 'gpt-4o', 'messages' => [['role' => 'user', 'content' => 'hi']]],
        'response' => ['choices' => [['message' => ['content' => 'hello']]]],
    ]);

    $response = app()->call([app(ShowRequestController::class), '__invoke'], [
        'id' => $request->id,
    ]);
    $json = $response->toArray();

    // Hidden by default on the model, but the detail endpoint re-exposes them.
    expect($json['request']['model'] ?? null)->toBe('gpt-4o')
        ->and($json['response']['choices'][0]['message']['content'] ?? null)->toBe('hello');
});

it('hides request and response payloads from the list endpoint', function () {
    SpectraRequest::factory()->create([
        'model_type' => 'text',
        'request' => ['model' => 'gpt-4o'],
        'response' => ['choices' => []],
    ]);

    $controller = app(RequestsController::class);
    $response = app()->call($controller->__invoke(...), ['request' => new Request(['period' => 'month'])]);
    $json = $response->toArray();

    expect($json['data'])->toHaveCount(1)
        ->and($json['data'][0])->not->toHaveKey('request')
        ->and($json['data'][0])->not->toHaveKey('response');
});

it('serves persisted tts audio with the stored mime type', function () {
    Storage::fake('local');
    Storage::disk('local')->put('spectra/req-3/0.wav', 'wav-bytes');

    $request = SpectraRequest::factory()->create([
        'model_type' => 'tts',
        'media_storage_path' => ['spectra/req-3/0.wav'],
    ]);

    $response = app()->call([app(ServeAudioController::class), '__invoke'], [
        'id' => $request->id,
        'action' => null,
    ]);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toBe('audio/wav')
        ->and($response->getContent())->toBe('wav-bytes');
});

it('streams Sora videos from the configured OpenAI base URL', function () {
    config(['spectra.api_keys.openai' => 'sk-test', 'spectra.endpoint_urls.openai' => 'https://gateway.example.com/openai/v1/']);
    Http::fake(['gateway.example.com/*' => Http::response('mp4-bytes')]);
    $request = SpectraRequest::factory()->create(['provider' => 'openai', 'model_type' => 'video', 'response' => ['id' => 'video_123']]);

    $response = app()->call([app(DownloadVideoController::class), '__invoke'], ['id' => $request->id]);

    expect($response->getStatusCode())->toBe(200);
    Http::assertSent(fn ($sent) => $sent->url() === 'https://gateway.example.com/openai/v1/videos/video_123/content');
});

it('answers 404 when a stored Sora response has no usable video id', function () {
    $request = SpectraRequest::factory()->create(['provider' => 'openai', 'model_type' => 'video', 'response' => ['id' => '']]);

    app()->call([app(DownloadVideoController::class), '__invoke'], ['id' => $request->id]);
})->throws(NotFoundHttpException::class);

it('leaves failed calls out of a trace total cost', function () {
    $current = SpectraRequest::factory()->create(['trace_id' => '0b6f3a52-6d47-4a5e-9a51-2a7c1f0e9d11', 'status_code' => 200, 'total_cost_in_cents' => 40]);
    SpectraRequest::factory()->create(['trace_id' => '0b6f3a52-6d47-4a5e-9a51-2a7c1f0e9d11', 'status_code' => 500, 'total_cost_in_cents' => 900]);

    $json = app()->call([app(ShowRequestController::class), '__invoke'], ['id' => $current->id])->toArray();

    expect($json['trace']['request_count'])->toBe(2)
        ->and($json['trace']['total_cost_in_cents'])->toEqualWithDelta(40.0, 0.0001);
});

it('returns tag ids as their UUIDs', function () {
    $tag = SpectraTag::findOrCreateByName('feature:search');

    $json = app()->call([app(TagsController::class), '__invoke'], [])->toArray();

    expect($json['tags'][0]['id'])->toBe($tag->id);
});
