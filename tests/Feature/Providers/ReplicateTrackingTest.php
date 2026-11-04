<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;

/**
 * Replicate predictions through the HTTP client: a polled prediction is
 * recorded once, when it succeeds, with video priced by length and
 * resolution and language models by their token metrics.
 */
it('records a prediction with iso timestamps and takes its latency from them', function () {
    // The fixture keeps Replicate's ISO-8601 timestamps; they used to crash
    // the persister, which subtracted them as numbers.
    Http::fake(['api.replicate.com/*' => Http::response($this->loadMockResponse('replicate/prediction.json'), 201)]);

    $response = Http::withHeaders(['Prefer' => 'wait'])
        ->post('https://api.replicate.com/v1/models/stability-ai/stable-diffusion/predictions', [
            'input' => ['prompt' => 'A beautiful sunset over mountains'],
        ]);

    $record = SpectraRequest::query()->sole();

    // completed_at − created_at (no started_at) = 4 s
    expect($response->status())->toBe(201)
        ->and($record->response_id)->toBe('p7pxyz123abc')
        ->and($record->latency_ms)->toBe(4000);
});

it('records a polled prediction once, when it succeeds', function () {
    $id = 'q6a2kzr8ksrme0cs9qwbx5c2y8';

    Http::fake([
        'api.replicate.com/v1/predictions' => Http::response($this->loadMockResponse('replicate/prediction-starting.json'), 201),
        'api.replicate.com/v1/predictions/*' => Http::sequence()
            ->push($this->loadMockResponse('replicate/prediction-processing.json'))
            ->push($this->loadMockResponse('replicate/prediction-flux-1.1-pro.json'))
            ->push($this->loadMockResponse('replicate/prediction-flux-1.1-pro.json')),
    ]);

    Http::post('https://api.replicate.com/v1/predictions', [
        'version' => 'black-forest-labs/flux-1.1-pro',
        'input' => ['prompt' => 'A lighthouse at dawn, watercolor'],
    ]);
    Http::get("https://api.replicate.com/v1/predictions/{$id}");

    expect(SpectraRequest::query()->count())->toBe(0);

    Http::get("https://api.replicate.com/v1/predictions/{$id}");
    Http::get("https://api.replicate.com/v1/predictions/{$id}");

    $record = SpectraRequest::query()->sole();

    // A single URL output is an image, not a video: flux-1.1-pro is $0.04 per image.
    expect($record->provider)->toBe('replicate')
        ->and($record->response_id)->toBe($id)
        ->and($record->model)->toBe('FLUX 1.1 Pro')
        ->and($record->model_type)->toBe('image')
        ->and($record->image_count)->toBe(1)
        ->and($record->finish_reason)->toBe('succeeded')
        // completed_at 10:00:04.750 − started_at 10:00:01.500
        ->and($record->latency_ms)->toBe(3250)
        ->and($record->total_cost_in_cents)->toEqualWithDelta(4.0, 0.000001)
        ->and((int) SpectraDailyStat::query()->sum('request_count'))->toBe(1)
        ->and((float) SpectraDailyStat::query()->sum('total_cost_in_cents'))->toEqualWithDelta(4.0, 0.000001);
});

it('records deployment predictions with the model from the response', function () {
    Http::fake(['api.replicate.com/*' => Http::response($this->loadMockResponse('replicate/prediction-flux-1.1-pro.json'), 201)]);

    Http::post('https://api.replicate.com/v1/deployments/acme/flux-pro-eu/predictions', ['input' => ['prompt' => 'x']]);

    expect(SpectraRequest::query()->sole()->model)->toBe('FLUX 1.1 Pro')
        ->and(SpectraRequest::query()->sole()->total_cost_in_cents)->toEqualWithDelta(4.0, 0.000001);
});

it('records a failed prediction without cost', function () {
    Http::fake(['api.replicate.com/*' => Http::response($this->loadMockResponse('replicate/prediction-failed.json'))]);

    Http::get('https://api.replicate.com/v1/predictions/x1hfa7zq3hrmc0cs9qwbtyd0rg');

    $record = SpectraRequest::query()->sole();

    expect($record->finish_reason)->toBe('failed')
        ->and($record->image_count)->toBe(0)
        ->and($record->total_cost_in_cents)->toEqualWithDelta(0.0, 0.000001);
});

it('prices wan video by the seconds the input asked for', function () {
    Http::fake(['api.replicate.com/*' => Http::response($this->loadMockResponse('replicate/prediction-wan-480p.json'))]);

    Http::get('https://api.replicate.com/v1/predictions/8rs0w5kq1nrmc0cs9qwv7tpbxc');

    $record = SpectraRequest::query()->sole();

    // 81 frames / 16 fps = 5.0625 s × 9 cents per second = 45.5625 cents
    expect($record->model_type)->toBe('video')
        ->and($record->video_count)->toBe(1)
        ->and($record->duration_seconds)->toEqualWithDelta(5.0625, 0.001)
        ->and($record->total_cost_in_cents)->toEqualWithDelta(45.5625, 0.000001);
});

it('bills 1080p veo output at the hd price', function () {
    Http::fake(['api.replicate.com/*' => Http::response($this->loadMockResponse('replicate/prediction-veo-1080p.json'))]);

    Http::get('https://api.replicate.com/v1/predictions/2b7nvfw0f5rmc0cs9qx0jq3m9w');

    $record = SpectraRequest::query()->sole();

    // 8 s × 8 cents per second (1080p) = 64 cents
    expect($record->model)->toBe('Veo 3.1 Lite')
        ->and($record->pricing_tier)->toBe('hd')
        ->and($record->duration_seconds)->toEqualWithDelta(8.0, 0.001)
        ->and($record->total_cost_in_cents)->toEqualWithDelta(64.0, 0.000001);
});

it('prices official language models by the token counts in the metrics', function () {
    Http::fake(['api.replicate.com/*' => Http::response($this->loadMockResponse('replicate/prediction-claude-sonnet-5.json'))]);

    Http::post('https://api.replicate.com/v1/models/anthropic/claude-sonnet-5/predictions', [
        'input' => ['prompt' => 'Summarise the attached policy in three bullet points.'],
    ]);

    $record = SpectraRequest::query()->sole();

    // (1,200 × 200 + 350 × 1000) / 1M = 0.59 cents
    expect($record->model)->toBe('Claude Sonnet 5')
        ->and($record->model_type)->toBe('text')
        ->and($record->prompt_tokens)->toBe(1200)
        ->and($record->completion_tokens)->toBe(350)
        ->and($record->total_cost_in_cents)->toEqualWithDelta(0.59, 0.000001);
});
