<?php

use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Spectra\Http\Controllers\Api\TrackablesController;
use Spectra\Models\SpectraRequest;

it('applies request-style filters in trackables endpoint', function () {
    [$included] = SpectraRequest::factory()
        ->count(3)
        ->state(new Sequence(
            ['model' => 'gpt-4o', 'model_type' => 'text', 'finish_reason' => 'stop', 'has_tool_calls' => false, 'trace_id' => 'trace-include', 'prompt_tokens' => 120, 'completion_tokens' => 80, 'total_cost_in_cents' => 100, 'trackable_type' => 'App\\Models\\User', 'trackable_id' => 1],
            ['model' => 'gpt-image-1', 'model_type' => 'image', 'status_code' => 500, 'finish_reason' => 'error', 'has_tool_calls' => true, 'trace_id' => 'trace-excluded', 'image_count' => 2, 'total_cost_in_cents' => 200, 'trackable_type' => 'App\\Models\\User', 'trackable_id' => 1],
            ['provider' => 'anthropic', 'model' => 'claude-3-5-sonnet', 'model_type' => 'text', 'finish_reason' => 'stop', 'has_tool_calls' => false, 'trace_id' => 'trace-other-provider', 'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_cost_in_cents' => 50, 'trackable_type' => 'App\\Models\\Team', 'trackable_id' => 2],
        ))
        ->create();

    $included->attachTags(['priority']);

    $controller = app(TrackablesController::class);
    $response = app()->call([$controller, '__invoke'], ['request' => new Request([
        'period' => 'all',
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'model_type' => 'text',
        'status' => 'success',
        'has_tool_calls' => '0',
        'trace_id' => 'trace-include',
        'tag' => 'priority',
    ])]);

    $json = $response->toArray();

    expect($json['total'])->toBe(1)
        ->and($json['data'])->toHaveCount(1)
        ->and($json['data'][0]['trackable_type'])->toBe('App\\Models\\User')
        ->and($json['data'][0]['trackable_id'])->toBe(1)
        ->and($json['data'][0]['requests'])->toBe(1)
        ->and($json)->toHaveKey('available_tags')
        ->and($json)->toHaveKey('available_finish_reasons')
        ->and($json['available_tags'])->toContain('priority');
});

it('filters trackables by custom date range', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-12 12:00:00'));

    try {
        SpectraRequest::factory()
            ->count(2)
            ->state(new Sequence(
                ['model' => 'gpt-4o', 'model_type' => 'text', 'prompt_tokens' => 100, 'completion_tokens' => 20, 'total_cost_in_cents' => 70, 'trackable_type' => 'App\\Models\\User', 'trackable_id' => 7, 'created_at' => Carbon::parse('2026-02-10 08:00:00')],
                ['model' => 'gpt-4o-mini', 'model_type' => 'text', 'prompt_tokens' => 50, 'completion_tokens' => 10, 'total_cost_in_cents' => 40, 'trackable_type' => 'App\\Models\\User', 'trackable_id' => 8, 'created_at' => Carbon::parse('2026-02-12 08:00:00')],
            ))
            ->create();

        $controller = app(TrackablesController::class);
        $response = app()->call([$controller, '__invoke'], ['request' => new Request([
            'period' => 'custom',
            'start_date' => '2026-02-10',
            'end_date' => '2026-02-10',
        ])]);

        $json = $response->toArray();

        expect($json['total'])->toBe(1)
            ->and($json['data'])->toHaveCount(1)
            ->and($json['data'][0]['trackable_id'])->toBe(7)
            ->and($json['summary']['total_requests'])->toBe(1);
    } finally {
        Carbon::setTestNow();
    }
});
