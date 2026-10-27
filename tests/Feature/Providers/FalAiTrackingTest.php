<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;

it('records fal.ai image generation response to database', function (string $fixture, string $endpoint, int $expectedImages) {
    $response = $this->loadMockResponse($fixture);

    $context = Spectra::startRequest('falai', 'fal-ai/fast-sdxl');
    $context->endpoint = $endpoint;

    $record = Spectra::recordSuccess($context, $response, [
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
    ]);

    expect($record)->toBeInstanceOf(SpectraRequest::class)
        ->and($record->provider)->toBe('falai')
        ->and($record->status_code)->toBe(200)
        ->and($record->image_count)->toBe($expectedImages);
})->with([
    'sync response' => ['falai/sync-image.json', '/fal-ai/fast-sdxl', 1],
    'queue response' => ['falai/queue-image.json', '/fal-ai/fast-sdxl', 1],
]);

it('records a queued job once, from its result, with the model and request id', function () {
    $id = '764cabcf-b745-4b3e-ae38-1200304cf45b';
    $base = "https://queue.fal.run/fal-ai/flux/dev/requests/{$id}";

    Http::fake([
        'queue.fal.run/fal-ai/flux/dev' => Http::response($this->loadMockResponse('falai/queue-submit.json')),
        "queue.fal.run/fal-ai/flux/dev/requests/{$id}/status*" => Http::sequence()
            ->push($this->loadMockResponse('falai/queue-status-in-progress.json'), 202)
            ->push($this->loadMockResponse('falai/queue-status-completed.json')),
        "queue.fal.run/fal-ai/flux/dev/requests/{$id}" => Http::response($this->loadMockResponse('falai/queue-result-flux-dev.json')),
    ]);

    Http::withHeaders(['Authorization' => 'Key test'])->post('https://queue.fal.run/fal-ai/flux/dev', ['prompt' => 'a sunset over mountains', 'num_images' => 2]);
    Http::get("{$base}/status?logs=1");
    Http::get("{$base}/status");

    expect(SpectraRequest::count())->toBe(0);

    Http::get($base);
    Http::get($base);

    $record = SpectraRequest::sole();

    // FLUX.1 dev bills $0.025 per megapixel, each image rounded up to whole
    // 1024×1024 megapixels: 1024×768 → 1 MP, 1920×1080 → 2 MP; 3 × 2.5 = 7.5 cents.
    expect($record->model)->toBe('FLUX.1 Dev')
        ->and($record->response_id)->toBe($id)
        ->and($record->model_type)->toBe('image')
        ->and($record->image_count)->toBe(2)
        ->and($record->total_cost_in_cents)->toEqualWithDelta(7.5, 0.000001)
        ->and((int) SpectraDailyStat::sum('request_count'))->toBe(1);
});

it('never records the status event stream', function () {
    Http::fake(['queue.fal.run/*' => Http::response(
        "data: {\"status\": \"IN_PROGRESS\", \"request_id\": \"abc\"}\n\ndata: {\"status\": \"COMPLETED\", \"request_id\": \"abc\"}\n\n",
        200,
        ['Content-Type' => 'text/event-stream'],
    )]);

    $response = Http::get('https://queue.fal.run/fal-ai/flux/requests/abc/status/stream');

    expect($response->body())->toContain('COMPLETED')
        ->and(SpectraRequest::count())->toBe(0);
});

it('takes the model from the path when the result url drops the sub-path', function () {
    Http::fake(['queue.fal.run/*' => Http::response($this->loadMockResponse('falai/queue-result-flux-dev.json'))]);

    Http::get('https://queue.fal.run/fal-ai/flux/requests/764cabcf-b745-4b3e-ae38-1200304cf45b');

    expect(SpectraRequest::sole()->model)->toBe('fal-ai/flux')
        ->and(SpectraRequest::sole()->response_id)->toBe('764cabcf-b745-4b3e-ae38-1200304cf45b');
});

it('records video results as videos, not as zero images', function () {
    Http::fake(['queue.fal.run/*' => Http::response($this->loadMockResponse('falai/queue-result-video.json'))]);

    Http::get('https://queue.fal.run/fal-ai/kling-video/requests/9a1b2c3d-0000-4e5f-8a9b-123456789abc');

    $record = SpectraRequest::sole();

    expect($record->model_type)->toBe('video')
        ->and($record->video_count)->toBe(1)
        ->and($record->image_count)->toBeNull()
        ->and($record->model)->toBe('fal-ai/kling-video');
});

it('records direct calls to fal.run per image', function () {
    Http::fake(['fal.run/*' => Http::response($this->loadMockResponse('falai/sync-nano-banana.json'))]);

    Http::post('https://fal.run/fal-ai/nano-banana/edit', ['prompt' => 'add a tiny wizard hat', 'image_urls' => ['https://example.com/cat.png']]);

    $record = SpectraRequest::sole();

    // nano-banana/edit bills like nano-banana: $0.0398 per image
    expect($record->model)->toBe('Nano Banana')
        ->and($record->image_count)->toBe(1)
        ->and($record->total_cost_in_cents)->toEqualWithDelta(3.98, 0.000001);
});

it('doubles the recraft price for vector styles', function (string $style, float $expected) {
    Http::fake(['fal.run/*' => Http::response($this->loadMockResponse('falai/sync-image.json'))]);

    Http::post('https://fal.run/fal-ai/recraft/v3/text-to-image', ['prompt' => 'a fox logo', 'style' => $style]);

    expect(SpectraRequest::sole()->total_cost_in_cents)->toEqualWithDelta($expected, 0.000001);
})->with([
    // $0.04 per image, $0.08 for vector styles
    'raster' => ['realistic_image', 4.0],
    'vector' => ['vector_illustration/line_art', 8.0],
]);

it('bills flux pro ultra per image whatever its size', function () {
    Http::fake(['fal.run/*' => Http::response([
        'images' => [['url' => 'https://v3.fal.media/files/a.jpg', 'width' => 2752, 'height' => 1536, 'content_type' => 'image/jpeg']],
        'seed' => 7,
        'has_nsfw_concepts' => [false],
        'prompt' => 'x',
    ])]);

    Http::post('https://fal.run/fal-ai/flux-pro/v1.1-ultra', ['prompt' => 'x']);

    // $0.06 per image
    expect(SpectraRequest::sole()->total_cost_in_cents)->toEqualWithDelta(6.0, 0.000001);
});

/**
 * Submits a queued video job the way fal's clients do, polls its status at
 * the model's app path (without the sub-path), and fetches its result there.
 *
 * @param  array<string, mixed>  $input
 */
function runQueuedFalVideo(string $model, array $input): void
{
    $id = '01a17cef-1744-7083-8396-43b1be6d649c';
    $app = implode('/', array_slice(explode('/', $model), 0, 2));
    $base = "https://queue.fal.run/{$app}/requests/{$id}";

    Http::fake([
        "queue.fal.run/{$model}" => Http::response([
            'request_id' => $id,
            'response_url' => $base,
            'status_url' => "{$base}/status",
            'cancel_url' => "{$base}/cancel",
            'queue_position' => 0,
        ]),
        "queue.fal.run/{$app}/requests/{$id}/status" => Http::response(['status' => 'COMPLETED', 'request_id' => $id]),
        "queue.fal.run/{$app}/requests/{$id}" => Http::response(json_decode((string) file_get_contents(__DIR__.'/../../responses/falai/queue-result-video.json'), true)),
    ]);

    Http::post("https://queue.fal.run/{$model}", $input);
    Http::get("{$base}/status");
    Http::get($base);
}

it('bills a queued video by the length its submit request asked for', function () {
    runQueuedFalVideo('fal-ai/kling-video/v3/pro/text-to-video', ['prompt' => 'a red fox in the snow', 'duration' => '5', 'aspect_ratio' => '16:9']);

    $record = SpectraRequest::sole();

    // Kling 3.0 Pro with audio (the default): $0.168 per second × 5 s.
    expect($record->model)->toBe('Kling 3.0 Pro Text-to-Video')
        ->and($record->model_type)->toBe('video')
        ->and($record->video_count)->toBe(1)
        ->and((float) $record->duration_seconds)->toEqualWithDelta(5.0, 1e-9)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(84.0, 1e-6)
        ->and($record->request)->toMatchArray(['prompt' => 'a red fox in the snow', 'duration' => '5']);
});

it('prices fal video by resolution and audio', function (string $model, array $input, float $seconds, float $cents) {
    runQueuedFalVideo($model, ['prompt' => 'a lighthouse at dusk', ...$input]);

    $record = SpectraRequest::sole();

    expect((float) $record->duration_seconds)->toEqualWithDelta($seconds, 1e-9)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta($cents, 1e-6);
})->with([
    // Per-second prices from each model page (2026-10-27).
    'kling without audio' => ['fal-ai/kling-video/v3/pro/text-to-video', ['duration' => '10', 'generate_audio' => false], 10.0, 112.0],
    'veo 3.1 defaults to 720p with audio' => ['fal-ai/veo3.1', ['duration' => '8s'], 8.0, 320.0],
    'veo 3.1 4k without audio' => ['fal-ai/veo3.1', ['duration' => '4s', 'resolution' => '4k', 'generate_audio' => false], 4.0, 160.0],
    'veo 3.1 fast 1080p' => ['fal-ai/veo3.1/fast', ['duration' => '6s', 'resolution' => '1080p'], 6.0, 90.0],
    'veo 3.1 fast 4k without audio' => ['fal-ai/veo3.1/fast', ['duration' => '8s', 'resolution' => '4k', 'generate_audio' => false], 8.0, 240.0],
    'veo 3.1 lite 720p without audio' => ['fal-ai/veo3.1/lite', ['duration' => '4s', 'generate_audio' => false], 4.0, 12.0],
    'veo 3.1 lite 1080p' => ['fal-ai/veo3.1/lite', ['duration' => '6s', 'resolution' => '1080p'], 6.0, 48.0],
    // Wan counts 16 frames as a second: 81 frames = 5.0625 s.
    'wan 720p from frames' => ['fal-ai/wan/v2.2-a14b/text-to-video', ['num_frames' => 81, 'frames_per_second' => 16, 'resolution' => '720p'], 5.0625, 40.5],
    'wan 480p' => ['fal-ai/wan/v2.2-a14b/text-to-video', ['num_frames' => 161, 'resolution' => '480p'], 10.0625, 40.25],
]);

it('bills a direct video call from its own request body', function () {
    Http::fake(['fal.run/*' => Http::response($this->loadMockResponse('falai/queue-result-video.json'))]);

    Http::post('https://fal.run/fal-ai/veo3.1/fast', ['prompt' => 'waves', 'duration' => '4s', 'generate_audio' => false]);

    // $0.10 per second without audio × 4 s.
    expect((float) SpectraRequest::sole()->total_cost_in_cents)->toEqualWithDelta(40.0, 1e-6);
});

it('keeps inline files out of the remembered submit body', function () {
    $image = 'data:image/png;base64,'.str_repeat('A', 4000);

    runQueuedFalVideo('fal-ai/kling-video/v3/pro/text-to-video', ['prompt' => 'animate this', 'duration' => '5', 'image_url' => $image, 'elements' => [['image_url' => $image, 'label' => 'cat']]]);

    $remembered = Cache::get('spectra:job-model:falai:'.sha1('01a17cef-1744-7083-8396-43b1be6d649c'));

    expect($remembered['model'])->toBe('fal-ai/kling-video/v3/pro/text-to-video')
        ->and($remembered['request'])->toBe(['prompt' => 'animate this', 'duration' => '5', 'elements' => [['label' => 'cat']]])
        ->and((float) SpectraRequest::sole()->total_cost_in_cents)->toEqualWithDelta(84.0, 1e-6);
});

it('still reads a job model cached as a string by an earlier version', function () {
    $id = '764cabcf-b745-4b3e-ae38-1200304cf45b';
    Cache::put('spectra:job-model:falai:'.sha1($id), 'fal-ai/flux/dev', now()->addDay());

    Http::fake(['queue.fal.run/*' => Http::response($this->loadMockResponse('falai/queue-result-flux-dev.json'))]);

    Http::get("https://queue.fal.run/fal-ai/flux/requests/{$id}");

    expect(SpectraRequest::sole()->model)->toBe('FLUX.1 Dev');
});
