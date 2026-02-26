<?php

use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Spectra\Http\Controllers\Api\CostsController;
use Spectra\Models\SpectraRequest;

it('returns model usage metrics by model type in costs endpoint', function () {
    SpectraRequest::factory()
        ->count(6)
        ->state(new Sequence(
            ['model' => 'gpt-4o', 'model_type' => 'text', 'prompt_tokens' => 120, 'completion_tokens' => 80, 'total_cost_in_cents' => 100],
            ['model' => 'gpt-image-1', 'model_type' => 'image', 'image_count' => 2, 'total_cost_in_cents' => 30],
            ['model' => 'sora-2', 'model_type' => 'video', 'video_count' => 1, 'duration_seconds' => 12.5, 'total_cost_in_cents' => 60],
            ['model' => 'tts-1', 'model_type' => 'tts', 'input_characters' => 1500, 'duration_seconds' => 8.0, 'total_cost_in_cents' => 15],
            ['model' => 'whisper-1', 'model_type' => 'stt', 'duration_seconds' => 25.0, 'total_cost_in_cents' => 5],
            ['model' => 'excluded-failed-model', 'model_type' => 'text', 'prompt_tokens' => 1000, 'completion_tokens' => 1000, 'total_cost_in_cents' => 9999, 'status_code' => 500],
        ))
        ->create();

    $controller = app(CostsController::class);
    $response = app()->call([$controller, '__invoke'], ['request' => new Request(['period' => 'month'])]);
    $json = $response->toArray();

    expect($json)->toHaveKey('costs_by_model')
        ->and($json)->toHaveKey('costs_by_model_type');

    $rows = collect($json['costs_by_model'])
        ->keyBy(fn (array $row) => $row['model'].'|'.$row['model_type']);

    expect($rows->has('gpt-4o|text'))->toBeTrue()
        ->and($rows['gpt-4o|text']['tokens'])->toEqual(200)
        ->and($rows->has('gpt-image-1|image'))->toBeTrue()
        ->and($rows['gpt-image-1|image']['images'])->toEqual(2)
        ->and($rows->has('sora-2|video'))->toBeTrue()
        ->and($rows['sora-2|video']['videos'])->toEqual(1)
        ->and($rows['sora-2|video']['duration_seconds'])->toEqual(12.5)
        ->and($rows->has('tts-1|tts'))->toBeTrue()
        ->and($rows['tts-1|tts']['input_characters'])->toEqual(1500)
        ->and($rows->has('whisper-1|stt'))->toBeTrue()
        ->and($rows['whisper-1|stt']['duration_seconds'])->toEqual(25)
        ->and(collect($json['costs_by_model'])->contains(fn (array $row) => $row['model'] === 'excluded-failed-model'))->toBeFalse();

    $typeRows = collect($json['costs_by_model_type'])->keyBy('model_type');

    expect($typeRows->has('text'))->toBeTrue()
        ->and($typeRows['text']['label'])->toBe('Text')
        ->and($typeRows['text']['tokens'])->toEqual(200)
        ->and((float) $typeRows['text']['cost'])->toBe(100.0)
        ->and($typeRows->has('image'))->toBeTrue()
        ->and($typeRows['image']['label'])->toBe('Image')
        ->and($typeRows['image']['images'])->toEqual(2)
        ->and((float) $typeRows['image']['cost'])->toBe(30.0)
        ->and($typeRows->has('video'))->toBeTrue()
        ->and($typeRows['video']['label'])->toBe('Video')
        ->and($typeRows['video']['videos'])->toEqual(1)
        ->and((float) $typeRows['video']['cost'])->toBe(60.0)
        ->and($typeRows->has('tts'))->toBeTrue()
        ->and($typeRows['tts']['label'])->toBe('Text-to-Speech')
        ->and($typeRows['tts']['input_characters'])->toEqual(1500)
        ->and((float) $typeRows['tts']['cost'])->toBe(15.0)
        ->and($typeRows->has('stt'))->toBeTrue()
        ->and($typeRows['stt']['label'])->toBe('Speech-to-Text')
        ->and((float) $typeRows['stt']['cost'])->toBe(5.0);
});

it('returns cost overview totals for dashboard cards', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-12 12:00:00'));

    try {
        SpectraRequest::factory()
            ->count(5)
            ->state(new Sequence(
                ['model' => 'gpt-4o', 'model_type' => 'text', 'total_cost_in_cents' => 100, 'created_at' => Carbon::parse('2026-02-12 09:00:00')],
                ['model' => 'gpt-4o-mini', 'model_type' => 'text', 'total_cost_in_cents' => 200, 'created_at' => Carbon::parse('2026-02-10 10:00:00')],
                ['model' => 'gpt-4o-audio', 'model_type' => 'tts', 'total_cost_in_cents' => 300, 'created_at' => Carbon::parse('2026-02-04 15:00:00')],
                ['model' => 'gpt-4.1', 'model_type' => 'text', 'total_cost_in_cents' => 400, 'created_at' => Carbon::parse('2026-01-15 13:00:00')],
                ['model' => 'excluded-error', 'model_type' => 'text', 'total_cost_in_cents' => 9000, 'status_code' => 500, 'created_at' => Carbon::parse('2026-02-12 11:00:00')],
            ))
            ->create();

        $controller = app(CostsController::class);
        $response = app()->call([$controller, '__invoke'], ['request' => new Request(['period' => 'month'])]);
        $json = $response->toArray();

        expect($json)->toHaveKey('cost_overview')
            ->and($json['cost_overview']['today'])->toEqual(100)
            ->and($json['cost_overview']['this_week'])->toEqual(300)
            ->and($json['cost_overview']['last_week'])->toEqual(300)
            ->and($json['cost_overview']['this_month'])->toEqual(600)
            ->and($json['cost_overview']['this_year'])->toEqual(1000);
    } finally {
        Carbon::setTestNow();
    }
});

it('filters costs by custom date range', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-12 12:00:00'));

    try {
        SpectraRequest::factory()
            ->count(2)
            ->state(new Sequence(
                ['model' => 'gpt-4o', 'model_type' => 'text', 'total_cost_in_cents' => 120, 'created_at' => Carbon::parse('2026-02-10 08:00:00')],
                ['model' => 'gpt-4o-mini', 'model_type' => 'text', 'total_cost_in_cents' => 80, 'created_at' => Carbon::parse('2026-02-12 08:00:00')],
            ))
            ->create();

        $controller = app(CostsController::class);
        $response = app()->call([$controller, '__invoke'], ['request' => new Request([
            'period' => 'custom',
            'start_date' => '2026-02-10',
            'end_date' => '2026-02-10',
        ])]);
        $json = $response->toArray();

        expect($json['total_cost_in_cents'])->toEqual(120)
            ->and($json['costs_by_date'])->toHaveCount(1)
            ->and($json['costs_by_date'][0]['date'])->toBe('2026-02-10');
    } finally {
        Carbon::setTestNow();
    }
});

it('returns all-time costs when period is all', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-12 12:00:00'));

    try {
        SpectraRequest::factory()
            ->count(2)
            ->state(new Sequence(
                ['model' => 'old-model', 'model_type' => 'text', 'total_cost_in_cents' => 120, 'created_at' => Carbon::parse('2025-01-10 08:00:00')],
                ['model' => 'new-model', 'model_type' => 'text', 'total_cost_in_cents' => 80, 'created_at' => Carbon::parse('2026-02-10 08:00:00')],
            ))
            ->create();

        $controller = app(CostsController::class);
        $response = app()->call([$controller, '__invoke'], ['request' => new Request([
            'period' => 'all',
        ])]);
        $json = $response->toArray();

        expect($json['total_cost_in_cents'])->toEqual(200)
            ->and($json['costs_by_date'])->toHaveCount(2);
    } finally {
        Carbon::setTestNow();
    }
});
