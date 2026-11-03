<?php

declare(strict_types=1);

use Spectra\Integrations\OpenTelemetry\DefaultSpanBuilder;

/**
 * DefaultSpanBuilder turns a tracked request into an OTLP span with the
 * gen_ai attributes, and can be extended.
 */
it('builds a client span named after the snapshot with a dashless trace id and a new span id', function () {
    $span = (new DefaultSpanBuilder)->build([
        'id' => 'req-123',
        'trace_id' => '550e8400-e29b-41d4-a716-446655440000',
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'snapshot' => 'gpt-4o-2024-05-13',
        'model_type' => 'text',
        'endpoint' => '/v1/chat/completions',
        'status_code' => 200,
        'is_failed' => false,
        'started_at' => now(),
        'completed_at' => now()->addMilliseconds(1500),
    ]);

    expect($span['name'])->toBe('chat gpt-4o-2024-05-13')
        ->and($span['kind'])->toBe(3) // SPAN_KIND_CLIENT
        ->and($span['parentSpanId'])->toBe('')
        ->and($span['traceId'])->toBe('550e8400e29b41d4a716446655440000')
        ->and($span['spanId'])->toHaveLength(16) // 8 bytes hex
        ->and($span['status'])->toBe(['code' => 1]); // STATUS_CODE_OK
});

it('sets error status for failed requests', function () {
    $span = (new DefaultSpanBuilder)->build([
        'id' => 'req-fail',
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-20250514',
        'status_code' => 500,
        'is_failed' => true,
        'started_at' => now(),
        'completed_at' => now(),
    ]);

    expect($span['status'])->toBe(['code' => 2, 'message' => 'Request failed']);
});

it('includes the standard gen_ai attributes without the deprecated gen_ai.system', function () {
    $span = (new DefaultSpanBuilder)->build([
        'id' => 'req-123',
        'trace_id' => 'abc-def',
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'prompt_tokens' => 100,
        'completion_tokens' => 50,
        'total_cost_in_cents' => 0.5,
        'latency_ms' => 1500,
        'status_code' => 200,
        'started_at' => now(),
        'completed_at' => now(),
    ]);
    $attrs = collect($span['attributes']);

    expect($attrs->firstWhere('key', 'gen_ai.provider.name')['value']['stringValue'])->toBe('openai')
        ->and($attrs->firstWhere('key', 'gen_ai.operation.name')['value']['stringValue'])->toBe('chat')
        ->and($attrs->firstWhere('key', 'gen_ai.request.model')['value']['stringValue'])->toBe('gpt-4o')
        ->and($attrs->firstWhere('key', 'gen_ai.usage.input_tokens')['value']['intValue'])->toBe(100)
        ->and($attrs->firstWhere('key', 'gen_ai.usage.output_tokens')['value']['intValue'])->toBe(50)
        ->and($attrs->firstWhere('key', 'gen_ai.system'))->toBeNull()
        ->and($attrs->firstWhere('key', 'spectra.cost_cents')['value']['doubleValue'])->toBe(0.5)
        ->and($attrs->firstWhere('key', 'spectra.latency_ms')['value']['intValue'])->toBe(1500);
});

it('includes conditional attributes when present', function () {
    $span = (new DefaultSpanBuilder)->build([
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
        'latency_ms' => 1500,
        'time_to_first_token_ms' => 200,
        'tokens_per_second' => 33.33,
        'status_code' => 200,
        'finish_reason' => 'stop',
        'trackable_type' => 'App\\Models\\User',
        'trackable_id' => 42,
        'started_at' => now(),
        'completed_at' => now(),
        'is_reasoning' => true,
        'reasoning_effort' => 'high',
        'is_streaming' => true,
        'has_tool_calls' => true,
        'duration_seconds' => 5.0,
    ]);
    $attrs = collect($span['attributes']);

    expect($attrs->firstWhere('key', 'gen_ai.response.model')['value']['stringValue'])->toBe('gpt-4o-2024-05-13')
        ->and($attrs->firstWhere('key', 'gen_ai.response.id')['value']['stringValue'])->toBe('resp-xyz')
        ->and($attrs->firstWhere('key', 'gen_ai.response.finish_reasons')['value']['arrayValue']['values'][0]['stringValue'])->toBe('stop')
        ->and($attrs->firstWhere('key', 'spectra.endpoint')['value']['stringValue'])->toBe('/v1/chat/completions')
        ->and($attrs->firstWhere('key', 'gen_ai.request.stream')['value']['boolValue'])->toBeTrue()
        ->and($attrs->firstWhere('key', 'spectra.model_type')['value']['stringValue'])->toBe('text')
        ->and($attrs->firstWhere('key', 'spectra.pricing_tier')['value']['stringValue'])->toBe('standard')
        ->and($attrs->firstWhere('key', 'spectra.trackable.type')['value']['stringValue'])->toBe('App\\Models\\User')
        ->and($attrs->firstWhere('key', 'spectra.trackable.id')['value']['stringValue'])->toBe('42')
        ->and($attrs->firstWhere('key', 'spectra.reasoning_effort')['value']['stringValue'])->toBe('high')
        ->and($attrs->firstWhere('key', 'spectra.has_tool_calls')['value']['boolValue'])->toBeTrue()
        ->and($attrs->firstWhere('key', 'spectra.time_to_first_token_ms')['value']['intValue'])->toBe(200)
        ->and($attrs->firstWhere('key', 'spectra.tokens_per_second')['value']['doubleValue'])->toBe(33.33)
        ->and($attrs->firstWhere('key', 'spectra.duration_seconds')['value']['doubleValue'])->toBe(5.0);
});

it('uses the span name from a subclass that overrides spanName', function () {
    $custom = new class extends DefaultSpanBuilder
    {
        protected function spanName(array $data): string
        {
            return "custom.{$data['provider']}.{$data['model']}";
        }
    };

    $span = $custom->build([
        'id' => 'req-123',
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'started_at' => now(),
        'completed_at' => now(),
    ]);

    expect($span['name'])->toBe('custom.openai.gpt-4o');
});
