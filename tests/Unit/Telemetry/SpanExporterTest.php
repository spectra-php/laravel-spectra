<?php

use Spectra\Contracts\SpanBuilder;
use Spectra\Integrations\OpenTelemetry\DefaultSpanBuilder;
use Spectra\Integrations\OpenTelemetry\SpanExporter;

it('delegates span creation to the injected span builder', function () {
    $mockBuilder = Mockery::mock(SpanBuilder::class);
    $mockBuilder->shouldReceive('build')
        ->once()
        ->with(Mockery::type('array'))
        ->andReturn(['name' => 'test-span', 'traceId' => 'abc']);

    $exporter = new SpanExporter('test-app', '1.0.0', [], $mockBuilder);

    $span = $exporter->createSpan(['provider' => 'openai', 'model' => 'gpt-4o']);

    expect($span['name'])->toBe('test-span');
});

it('uses DefaultSpanBuilder when none is provided', function () {
    $exporter = new SpanExporter('test-app');

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

    $span = $exporter->createSpan($data);

    expect($span['name'])->toBe('ai.openai.gpt-4o')
        ->and($span)->toHaveKeys(['traceId', 'spanId', 'attributes', 'status']);
});

it('wraps spans in OTLP batch envelope', function () {
    $exporter = new SpanExporter('my-app', '2.0.0', ['deployment.region' => 'us-east-1']);

    $batch = $exporter->exportBatch([
        ['name' => 'span-1'],
        ['name' => 'span-2'],
    ]);

    expect($batch)->toHaveKey('resourceSpans')
        ->and($batch['resourceSpans'])->toHaveCount(1)
        ->and($batch['resourceSpans'][0]['resource']['attributes'])->toBeArray()
        ->and($batch['resourceSpans'][0]['scopeSpans'][0]['scope']['name'])->toBe('laravel-spectra')
        ->and($batch['resourceSpans'][0]['scopeSpans'][0]['spans'])->toHaveCount(2);

    // Check resource attributes
    $attrs = collect($batch['resourceSpans'][0]['resource']['attributes']);
    expect($attrs->firstWhere('key', 'service.name')['value']['stringValue'])->toBe('my-app')
        ->and($attrs->firstWhere('key', 'service.version')['value']['stringValue'])->toBe('2.0.0')
        ->and($attrs->firstWhere('key', 'deployment.region')['value']['stringValue'])->toBe('us-east-1');
});

it('resolves custom span builder from config', function () {
    config()->set('spectra.integrations.opentelemetry.span_builder', DefaultSpanBuilder::class);

    $resolved = app(SpanBuilder::class);

    expect($resolved)->toBeInstanceOf(DefaultSpanBuilder::class);
});
