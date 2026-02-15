<?php

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Spectra\Http\Controllers\Api\CostsController;
use Spectra\Models\SpectraRequest;

it('returns model usage metrics by model type in costs endpoint', function () {
    SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'model_type' => 'text',
        'prompt_tokens' => 120,
        'completion_tokens' => 80,
        'total_cost_in_cents' => 100,
        'status_code' => 200,
        'created_at' => now(),
    ]);

    SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'gpt-image-1',
        'model_type' => 'image',
        'image_count' => 2,
        'total_cost_in_cents' => 30,
        'status_code' => 200,
        'created_at' => now(),
    ]);

    SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'sora-2',
        'model_type' => 'video',
        'video_count' => 1,
        'duration_seconds' => 12.5,
        'total_cost_in_cents' => 60,
        'status_code' => 200,
        'created_at' => now(),
    ]);

    SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'tts-1',
        'model_type' => 'tts',
        'input_characters' => 1500,
        'duration_seconds' => 8.0,
        'total_cost_in_cents' => 15,
        'status_code' => 200,
        'created_at' => now(),
    ]);

    SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'whisper-1',
        'model_type' => 'stt',
        'duration_seconds' => 25.0,
        'total_cost_in_cents' => 5,
        'status_code' => 200,
        'created_at' => now(),
    ]);

    // Failed request should be excluded from costs endpoint aggregates.
    SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'excluded-failed-model',
        'model_type' => 'text',
        'prompt_tokens' => 1000,
        'completion_tokens' => 1000,
        'total_cost_in_cents' => 9999,
        'status_code' => 500,
        'created_at' => now(),
    ]);

    $controller = app(CostsController::class);
    $response = app()->call([$controller, '__invoke'], ['request' => new Request(['period' => 'month'])]);
    $json = $response->toArray();

    expect($json)->toHaveKey('costs_by_model');
    expect($json)->toHaveKey('costs_by_model_type');

    $rows = collect($json['costs_by_model'])
        ->keyBy(fn (array $row) => $row['model'].'|'.$row['model_type']);

    expect($rows->has('gpt-4o|text'))->toBeTrue();
    expect($rows['gpt-4o|text']['tokens'])->toEqual(200);

    expect($rows->has('gpt-image-1|image'))->toBeTrue();
    expect($rows['gpt-image-1|image']['images'])->toEqual(2);

    expect($rows->has('sora-2|video'))->toBeTrue();
    expect($rows['sora-2|video']['videos'])->toEqual(1);
    expect($rows['sora-2|video']['duration_seconds'])->toEqual(12.5);

    expect($rows->has('tts-1|tts'))->toBeTrue();
    expect($rows['tts-1|tts']['input_characters'])->toEqual(1500);

    expect($rows->has('whisper-1|stt'))->toBeTrue();
    expect($rows['whisper-1|stt']['duration_seconds'])->toEqual(25);

    expect(collect($json['costs_by_model'])->contains(fn (array $row) => $row['model'] === 'excluded-failed-model'))->toBeFalse();

    $typeRows = collect($json['costs_by_model_type'])->keyBy('model_type');

    expect($typeRows->has('text'))->toBeTrue();
    expect($typeRows['text']['label'])->toBe('Text');
    expect($typeRows['text']['tokens'])->toEqual(200);
    expect((float) $typeRows['text']['cost'])->toBe(100.0);

    expect($typeRows->has('image'))->toBeTrue();
    expect($typeRows['image']['label'])->toBe('Image');
    expect($typeRows['image']['images'])->toEqual(2);
    expect((float) $typeRows['image']['cost'])->toBe(30.0);

    expect($typeRows->has('video'))->toBeTrue();
    expect($typeRows['video']['label'])->toBe('Video');
    expect($typeRows['video']['videos'])->toEqual(1);
    expect((float) $typeRows['video']['cost'])->toBe(60.0);

    expect($typeRows->has('tts'))->toBeTrue();
    expect($typeRows['tts']['label'])->toBe('Text-to-Speech');
    expect($typeRows['tts']['input_characters'])->toEqual(1500);
    expect((float) $typeRows['tts']['cost'])->toBe(15.0);

    expect($typeRows->has('stt'))->toBeTrue();
    expect($typeRows['stt']['label'])->toBe('Speech-to-Text');
    expect((float) $typeRows['stt']['cost'])->toBe(5.0);
});

it('returns cost overview totals for dashboard cards', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-12 12:00:00'));

    try {
        SpectraRequest::create([
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'model_type' => 'text',
            'total_cost_in_cents' => 100,
            'status_code' => 200,
            'created_at' => Carbon::parse('2026-02-12 09:00:00'),
        ]);

        SpectraRequest::create([
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'model_type' => 'text',
            'total_cost_in_cents' => 200,
            'status_code' => 200,
            'created_at' => Carbon::parse('2026-02-10 10:00:00'),
        ]);

        SpectraRequest::create([
            'provider' => 'openai',
            'model' => 'gpt-4o-audio',
            'model_type' => 'tts',
            'total_cost_in_cents' => 300,
            'status_code' => 200,
            'created_at' => Carbon::parse('2026-02-04 15:00:00'),
        ]);

        SpectraRequest::create([
            'provider' => 'openai',
            'model' => 'gpt-4.1',
            'model_type' => 'text',
            'total_cost_in_cents' => 400,
            'status_code' => 200,
            'created_at' => Carbon::parse('2026-01-15 13:00:00'),
        ]);

        SpectraRequest::create([
            'provider' => 'openai',
            'model' => 'excluded-error',
            'model_type' => 'text',
            'total_cost_in_cents' => 9000,
            'status_code' => 500,
            'created_at' => Carbon::parse('2026-02-12 11:00:00'),
        ]);

        $controller = app(CostsController::class);
        $response = app()->call([$controller, '__invoke'], ['request' => new Request(['period' => 'month'])]);
        $json = $response->toArray();

        expect($json)->toHaveKey('cost_overview');

        expect($json['cost_overview']['today'])->toEqual(100);
        expect($json['cost_overview']['this_week'])->toEqual(300);
        expect($json['cost_overview']['last_week'])->toEqual(300);
        expect($json['cost_overview']['this_month'])->toEqual(600);
        expect($json['cost_overview']['this_year'])->toEqual(1000);
    } finally {
        Carbon::setTestNow();
    }
});

it('filters costs by custom date range', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-12 12:00:00'));

    try {
        SpectraRequest::create([
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'model_type' => 'text',
            'total_cost_in_cents' => 120,
            'status_code' => 200,
            'created_at' => Carbon::parse('2026-02-10 08:00:00'),
        ]);

        SpectraRequest::create([
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'model_type' => 'text',
            'total_cost_in_cents' => 80,
            'status_code' => 200,
            'created_at' => Carbon::parse('2026-02-12 08:00:00'),
        ]);

        $controller = app(CostsController::class);
        $response = app()->call([$controller, '__invoke'], ['request' => new Request([
            'period' => 'custom',
            'start_date' => '2026-02-10',
            'end_date' => '2026-02-10',
        ])]);
        $json = $response->toArray();

        expect($json['total_cost_in_cents'])->toEqual(120);
        expect($json['costs_by_date'])->toHaveCount(1);
        expect($json['costs_by_date'][0]['date'])->toBe('2026-02-10');
    } finally {
        Carbon::setTestNow();
    }
});

it('returns all-time costs when period is all', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-12 12:00:00'));

    try {
        SpectraRequest::create([
            'provider' => 'openai',
            'model' => 'old-model',
            'model_type' => 'text',
            'total_cost_in_cents' => 120,
            'status_code' => 200,
            'created_at' => Carbon::parse('2025-01-10 08:00:00'),
        ]);

        SpectraRequest::create([
            'provider' => 'openai',
            'model' => 'new-model',
            'model_type' => 'text',
            'total_cost_in_cents' => 80,
            'status_code' => 200,
            'created_at' => Carbon::parse('2026-02-10 08:00:00'),
        ]);

        $controller = app(CostsController::class);
        $response = app()->call([$controller, '__invoke'], ['request' => new Request([
            'period' => 'all',
        ])]);
        $json = $response->toArray();

        expect($json['total_cost_in_cents'])->toEqual(200);
        expect($json['costs_by_date'])->toHaveCount(2);
    } finally {
        Carbon::setTestNow();
    }
});
