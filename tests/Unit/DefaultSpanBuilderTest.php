<?php

use Spectra\Integrations\OpenTelemetry\DefaultSpanBuilder;

it('builds a valid OTLP span from request data', function () {
    $data = [
        'id' => 'req-123',
        'trace_id' => '550e8400-e29b-41d4-a716-446655440000',
        'response_id' => 'resp-abc',
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'snapshot' => 'gpt-4o-2024-05-13',
        'model_type' => 'text',
        'endpoint' => '/v1/chat/completions',
        'pricing_tier' => 'standard',
        'prompt_tokens' => 100,
        'completion_tokens' => 50,
        'reasoning_tokens' => 0,
        'total_tokens' => 150,
        'total_cost_in_cents' => 0.5,
        'prompt_cost' => 0.3,
        'completion_cost' => 0.2,
        'latency_ms' => 1500,
        'time_to_first_token_ms' => null,
        'tokens_per_second' => null,
        'status_code' => 200,
        'is_failed' => false,
        'finish_reason' => 'stop',
        'trackable_type' => null,
        'trackable_id' => null,
        'started_at' => now(),
        'completed_at' => now()->addMilliseconds(1500),
        'is_reasoning' => false,
        'reasoning_effort' => null,
        'is_streaming' => false,
        'has_tool_calls' => false,
        'tool_call_counts' => null,
        'duration_seconds' => null,
        'input_characters' => null,
        'image_count' => null,
        'video_count' => null,
        'metadata' => null,
    ];

    $builder = new DefaultSpanBuilder;
    $span = $builder->build($data);

    expect($span)
        ->toBeArray()
        ->toHaveKeys(['traceId', 'spanId', 'parentSpanId', 'name', 'kind', 'startTimeUnixNano', 'endTimeUnixNano', 'attributes', 'status', 'events', 'links'])
        ->and($span['name'])->toBe('ai.openai.gpt-4o')
        ->and($span['kind'])->toBe(3) // SPAN_KIND_CLIENT
        ->and($span['parentSpanId'])->toBe('')
        ->and($span['traceId'])->toBe('550e8400e29b41d4a716446655440000')
        ->and($span['spanId'])->toHaveLength(16) // 8 bytes hex
        ->and($span['status'])->toBe(['code' => 1]); // STATUS_CODE_OK
});

it('sets error status for failed requests', function () {
    $data = [
        'id' => 'req-fail',
        'trace_id' => null,
        'response_id' => null,
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-20250514',
        'snapshot' => null,
        'model_type' => 'text',
        'endpoint' => null,
        'pricing_tier' => null,
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'reasoning_tokens' => 0,
        'total_tokens' => 0,
        'total_cost_in_cents' => 0,
        'prompt_cost' => 0,
        'completion_cost' => 0,
        'latency_ms' => null,
        'time_to_first_token_ms' => null,
        'tokens_per_second' => null,
        'status_code' => 500,
        'is_failed' => true,
        'finish_reason' => null,
        'trackable_type' => null,
        'trackable_id' => null,
        'started_at' => now(),
        'completed_at' => now(),
        'is_reasoning' => false,
        'reasoning_effort' => null,
        'is_streaming' => false,
        'has_tool_calls' => false,
        'tool_call_counts' => null,
        'duration_seconds' => null,
        'input_characters' => null,
        'image_count' => null,
        'video_count' => null,
        'metadata' => null,
    ];

    $span = (new DefaultSpanBuilder)->build($data);

    expect($span['status'])->toBe(['code' => 2, 'message' => 'Request failed']);
});

it('includes standard gen_ai attributes', function () {
    $data = [
        'id' => 'req-123',
        'trace_id' => 'abc-def',
        'response_id' => null,
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'snapshot' => null,
        'model_type' => null,
        'endpoint' => null,
        'pricing_tier' => null,
        'prompt_tokens' => 100,
        'completion_tokens' => 50,
        'reasoning_tokens' => 0,
        'total_tokens' => 150,
        'total_cost_in_cents' => 0.5,
        'prompt_cost' => 0.3,
        'completion_cost' => 0.2,
        'latency_ms' => 1500,
        'time_to_first_token_ms' => null,
        'tokens_per_second' => null,
        'status_code' => 200,
        'is_failed' => false,
        'finish_reason' => null,
        'trackable_type' => null,
        'trackable_id' => null,
        'started_at' => now(),
        'completed_at' => now(),
        'is_reasoning' => false,
        'reasoning_effort' => null,
        'is_streaming' => false,
        'has_tool_calls' => false,
        'tool_call_counts' => null,
        'duration_seconds' => null,
        'input_characters' => null,
        'image_count' => null,
        'video_count' => null,
        'metadata' => null,
    ];

    $span = (new DefaultSpanBuilder)->build($data);
    $attrs = collect($span['attributes']);

    expect($attrs->firstWhere('key', 'gen_ai.system')['value']['stringValue'])->toBe('openai')
        ->and($attrs->firstWhere('key', 'gen_ai.request.model')['value']['stringValue'])->toBe('gpt-4o')
        ->and($attrs->firstWhere('key', 'gen_ai.usage.prompt_tokens')['value']['intValue'])->toBe(100)
        ->and($attrs->firstWhere('key', 'gen_ai.usage.completion_tokens')['value']['intValue'])->toBe(50)
        ->and($attrs->firstWhere('key', 'gen_ai.usage.total_tokens')['value']['intValue'])->toBe(150)
        ->and($attrs->firstWhere('key', 'spectra.cost_cents')['value']['doubleValue'])->toBe(0.5)
        ->and($attrs->firstWhere('key', 'spectra.latency_ms')['value']['intValue'])->toBe(1500);
});

it('includes conditional attributes when present', function () {
    $data = [
        'id' => 'req-123',
        'trace_id' => 'abc-def',
        'response_id' => 'resp-xyz',
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'snapshot' => 'gpt-4o-2024-05-13',
        'model_type' => 'text',
        'endpoint' => '/v1/chat/completions',
        'pricing_tier' => 'standard',
        'prompt_tokens' => 100,
        'completion_tokens' => 50,
        'reasoning_tokens' => 25,
        'total_tokens' => 150,
        'total_cost_in_cents' => 0.5,
        'prompt_cost' => 0.3,
        'completion_cost' => 0.2,
        'latency_ms' => 1500,
        'time_to_first_token_ms' => 200,
        'tokens_per_second' => 33.33,
        'status_code' => 200,
        'is_failed' => false,
        'finish_reason' => 'stop',
        'trackable_type' => 'App\\Models\\User',
        'trackable_id' => 42,
        'started_at' => now(),
        'completed_at' => now(),
        'is_reasoning' => true,
        'reasoning_effort' => 'high',
        'is_streaming' => true,
        'has_tool_calls' => true,
        'tool_call_counts' => null,
        'duration_seconds' => 5.0,
        'input_characters' => null,
        'image_count' => null,
        'video_count' => null,
        'metadata' => null,
    ];

    $span = (new DefaultSpanBuilder)->build($data);
    $attrs = collect($span['attributes']);

    expect($attrs->firstWhere('key', 'gen_ai.response.model')['value']['stringValue'])->toBe('gpt-4o-2024-05-13')
        ->and($attrs->firstWhere('key', 'gen_ai.response.id')['value']['stringValue'])->toBe('resp-xyz')
        ->and($attrs->firstWhere('key', 'gen_ai.response.finish_reason')['value']['stringValue'])->toBe('stop')
        ->and($attrs->firstWhere('key', 'gen_ai.operation.name')['value']['stringValue'])->toBe('/v1/chat/completions')
        ->and($attrs->firstWhere('key', 'spectra.model_type')['value']['stringValue'])->toBe('text')
        ->and($attrs->firstWhere('key', 'spectra.pricing_tier')['value']['stringValue'])->toBe('standard')
        ->and($attrs->firstWhere('key', 'spectra.trackable.type')['value']['stringValue'])->toBe('App\\Models\\User')
        ->and($attrs->firstWhere('key', 'spectra.trackable.id')['value']['stringValue'])->toBe('42')
        ->and($attrs->firstWhere('key', 'spectra.reasoning_effort')['value']['stringValue'])->toBe('high')
        ->and($attrs->firstWhere('key', 'spectra.is_streaming')['value']['boolValue'])->toBeTrue()
        ->and($attrs->firstWhere('key', 'spectra.has_tool_calls')['value']['boolValue'])->toBeTrue()
        ->and($attrs->firstWhere('key', 'spectra.time_to_first_token_ms')['value']['intValue'])->toBe(200)
        ->and($attrs->firstWhere('key', 'spectra.tokens_per_second')['value']['doubleValue'])->toBe(33.33)
        ->and($attrs->firstWhere('key', 'spectra.duration_seconds')['value']['doubleValue'])->toBe(5.0);
});

it('can be extended with custom span name', function () {
    $custom = new class extends DefaultSpanBuilder
    {
        protected function spanName(array $data): string
        {
            return "custom.{$data['provider']}.{$data['model']}";
        }
    };

    $data = [
        'id' => 'req-123',
        'trace_id' => null,
        'response_id' => null,
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'snapshot' => null,
        'model_type' => null,
        'endpoint' => null,
        'pricing_tier' => null,
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'reasoning_tokens' => 0,
        'total_tokens' => 0,
        'total_cost_in_cents' => 0,
        'prompt_cost' => 0,
        'completion_cost' => 0,
        'latency_ms' => null,
        'time_to_first_token_ms' => null,
        'tokens_per_second' => null,
        'status_code' => 200,
        'is_failed' => false,
        'finish_reason' => null,
        'trackable_type' => null,
        'trackable_id' => null,
        'started_at' => now(),
        'completed_at' => now(),
        'is_reasoning' => false,
        'reasoning_effort' => null,
        'is_streaming' => false,
        'has_tool_calls' => false,
        'tool_call_counts' => null,
        'duration_seconds' => null,
        'input_characters' => null,
        'image_count' => null,
        'video_count' => null,
        'metadata' => null,
    ];

    $span = $custom->build($data);

    expect($span['name'])->toBe('custom.openai.gpt-4o');
});
