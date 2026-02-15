<?php

use Spectra\Models\SpectraRequest;
use Spectra\Support\RequestTransformer;

it('transforms a spectra request into a clean data array', function () {
    $startedAt = now();
    $completedAt = $startedAt->copy()->addMilliseconds(1500);

    $request = new SpectraRequest([
        'id' => 'test-id-123',
        'trace_id' => 'trace-abc',
        'response_id' => 'resp-xyz',
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'snapshot' => 'gpt-4o-2024-05-13',
        'model_type' => 'text',
        'endpoint' => '/v1/chat/completions',
        'pricing_tier' => 'standard',
        'prompt_tokens' => 100,
        'completion_tokens' => 50,
        'reasoning_tokens' => 10,
        'total_cost_in_cents' => 0.5,
        'prompt_cost' => 0.3,
        'completion_cost' => 0.2,
        'latency_ms' => 1500,
        'time_to_first_token_ms' => 200,
        'tokens_per_second' => 33.33,
        'status_code' => 200,
        'finish_reason' => 'stop',
        'is_reasoning' => true,
        'reasoning_effort' => 'medium',
        'is_streaming' => true,
        'has_tool_calls' => true,
        'tool_call_counts' => ['function_call' => 2],
        'duration_seconds' => 5.5,
        'input_characters' => 1000,
        'image_count' => 2,
        'video_count' => 1,
        'metadata' => ['key' => 'value'],
        'created_at' => $startedAt,
        'completed_at' => $completedAt,
    ]);

    $transformer = new RequestTransformer;
    $data = $transformer->transform($request);

    expect($data)
        ->toBeArray()
        ->and($data['id'])->toBe('test-id-123')
        ->and($data['trace_id'])->toBe('trace-abc')
        ->and($data['response_id'])->toBe('resp-xyz')
        ->and($data['provider'])->toBe('openai')
        ->and($data['model'])->toBe('gpt-4o')
        ->and($data['snapshot'])->toBe('gpt-4o-2024-05-13')
        ->and($data['model_type'])->toBe('text')
        ->and($data['endpoint'])->toBe('/v1/chat/completions')
        ->and($data['pricing_tier'])->toBe('standard')
        ->and($data['prompt_tokens'])->toBe(100)
        ->and($data['completion_tokens'])->toBe(50)
        ->and($data['reasoning_tokens'])->toBe(10)
        ->and($data['total_tokens'])->toBe(150)
        ->and($data['total_cost_in_cents'])->toBe(0.5)
        ->and($data['latency_ms'])->toBe(1500)
        ->and($data['is_failed'])->toBeFalse()
        ->and($data['finish_reason'])->toBe('stop')
        ->and($data['is_reasoning'])->toBeTrue()
        ->and($data['reasoning_effort'])->toBe('medium')
        ->and($data['is_streaming'])->toBeTrue()
        ->and($data['has_tool_calls'])->toBeTrue()
        ->and($data['tool_call_counts'])->toBe(['function_call' => 2])
        ->and($data['duration_seconds'])->toBe(5.5)
        ->and($data['input_characters'])->toBe(1000)
        ->and($data['image_count'])->toBe(2)
        ->and($data['video_count'])->toBe(1)
        ->and($data['metadata'])->toBe(['key' => 'value'])
        ->and($data['started_at'])->toBeInstanceOf(\Illuminate\Support\Carbon::class)
        ->and($data['completed_at'])->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

it('marks a failed request as is_failed', function () {
    $request = new SpectraRequest([
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'status_code' => 500,
        'created_at' => now(),
    ]);

    $data = (new RequestTransformer)->transform($request);

    expect($data['is_failed'])->toBeTrue();
});

it('reads completed_at directly from the model', function () {
    $startedAt = now();
    $completedAt = $startedAt->copy()->addMilliseconds(2000);

    $request = new SpectraRequest([
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'latency_ms' => 2000,
        'status_code' => 200,
        'created_at' => $startedAt,
        'completed_at' => $completedAt,
    ]);

    $data = (new RequestTransformer)->transform($request);

    expect($data['started_at']->timestamp)->toBe($startedAt->timestamp)
        ->and($data['completed_at']->timestamp)->toBe($completedAt->timestamp);
});

it('falls back to created_at when completed_at is null', function () {
    $now = now();

    $request = new SpectraRequest([
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'status_code' => 200,
        'created_at' => $now,
        'completed_at' => null,
    ]);

    $data = (new RequestTransformer)->transform($request);

    expect($data['started_at']->timestamp)->toBe($now->timestamp)
        ->and($data['completed_at']->timestamp)->toBe($now->timestamp);
});

it('excludes request, response, and internal fields', function () {
    $request = new SpectraRequest([
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'status_code' => 200,
        'created_at' => now(),
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

it('renames created_at to started_at', function () {
    $now = now();

    $request = new SpectraRequest([
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'status_code' => 200,
        'created_at' => $now,
    ]);

    $data = (new RequestTransformer)->transform($request);

    expect($data)->toHaveKey('started_at')
        ->and($data)->not->toHaveKey('created_at')
        ->and($data['started_at']->timestamp)->toBe($now->timestamp);
});
