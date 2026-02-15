<?php

namespace Spectra\Integrations\OpenTelemetry;

use Spectra\Contracts\SpanBuilder;

/**
 * Default OpenTelemetry span builder.
 *
 * Builds OTLP-compatible spans following the OpenTelemetry semantic conventions
 * for GenAI operations. Override protected methods for partial customization,
 * or implement SpanBuilder from scratch for full control.
 *
 * @see https://opentelemetry.io/docs/specs/semconv/gen-ai/
 */
class DefaultSpanBuilder implements SpanBuilder
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function build(array $data): array
    {
        return [
            'traceId' => $this->generateTraceId($data['trace_id'] ?? null),
            'spanId' => $this->generateSpanId(),
            'parentSpanId' => '',
            'name' => $this->spanName($data),
            'kind' => 3, // SPAN_KIND_CLIENT
            'startTimeUnixNano' => $this->toNano($data['started_at']),
            'endTimeUnixNano' => $this->toNano($data['completed_at']),
            'attributes' => $this->attributes($data),
            'status' => $this->status($data),
            'events' => $this->events($data),
            'links' => [],
        ];
    }

    /**
     * Build the span name.
     *
     * Default: "ai.{provider}.{model}" (e.g. "ai.openai.gpt-4o").
     */
    /**
     * @param  array<string, mixed>  $data
     */
    protected function spanName(array $data): string
    {
        return "ai.{$data['provider']}.{$data['model']}";
    }

    /**
     * Build the span attributes array.
     *
     * Each attribute is an OTLP key-value pair:
     * ['key' => 'name', 'value' => ['stringValue' => 'value']]
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{key: string, value: array<string, mixed>}>
     */
    protected function attributes(array $data): array
    {
        $attributes = [
            ['key' => 'gen_ai.system', 'value' => ['stringValue' => $data['provider']]],
            ['key' => 'gen_ai.request.model', 'value' => ['stringValue' => $data['model']]],
            ['key' => 'gen_ai.usage.prompt_tokens', 'value' => ['intValue' => $data['prompt_tokens']]],
            ['key' => 'gen_ai.usage.completion_tokens', 'value' => ['intValue' => $data['completion_tokens']]],
            ['key' => 'gen_ai.usage.total_tokens', 'value' => ['intValue' => $data['total_tokens']]],

            ['key' => 'spectra.trace_id', 'value' => ['stringValue' => $data['trace_id'] ?? '']],
            ['key' => 'spectra.request_id', 'value' => ['stringValue' => $data['id']]],
            ['key' => 'spectra.cost_cents', 'value' => ['doubleValue' => $data['total_cost_in_cents']]],
            ['key' => 'spectra.latency_ms', 'value' => ['intValue' => $data['latency_ms'] ?? 0]],

            ['key' => 'http.response.status_code', 'value' => ['intValue' => $data['status_code'] ?? 0]],
        ];

        if ($data['snapshot'] ?? null) {
            $attributes[] = ['key' => 'gen_ai.response.model', 'value' => ['stringValue' => $data['snapshot']]];
        }

        if ($data['response_id'] ?? null) {
            $attributes[] = ['key' => 'gen_ai.response.id', 'value' => ['stringValue' => $data['response_id']]];
        }

        if ($data['finish_reason'] ?? null) {
            $attributes[] = ['key' => 'gen_ai.response.finish_reason', 'value' => ['stringValue' => $data['finish_reason']]];
        }

        if ($data['model_type'] ?? null) {
            $attributes[] = ['key' => 'spectra.model_type', 'value' => ['stringValue' => $data['model_type']]];
        }

        if ($data['endpoint'] ?? null) {
            $attributes[] = ['key' => 'gen_ai.operation.name', 'value' => ['stringValue' => $data['endpoint']]];
        }

        if ($data['pricing_tier'] ?? null) {
            $attributes[] = ['key' => 'spectra.pricing_tier', 'value' => ['stringValue' => $data['pricing_tier']]];
        }

        if (($data['trackable_type'] ?? null) && ($data['trackable_id'] ?? null)) {
            $attributes[] = ['key' => 'spectra.trackable.type', 'value' => ['stringValue' => $data['trackable_type']]];
            $attributes[] = ['key' => 'spectra.trackable.id', 'value' => ['stringValue' => (string) $data['trackable_id']]];
        }

        if (($data['reasoning_tokens'] ?? 0) > 0) {
            $attributes[] = ['key' => 'gen_ai.usage.reasoning_tokens', 'value' => ['intValue' => $data['reasoning_tokens']]];
        }

        if (($data['is_reasoning'] ?? false) && ($data['reasoning_effort'] ?? null)) {
            $attributes[] = ['key' => 'spectra.reasoning_effort', 'value' => ['stringValue' => $data['reasoning_effort']]];
        }

        if ($data['is_streaming'] ?? false) {
            $attributes[] = ['key' => 'spectra.is_streaming', 'value' => ['boolValue' => true]];
        }

        if ($data['has_tool_calls'] ?? false) {
            $attributes[] = ['key' => 'spectra.has_tool_calls', 'value' => ['boolValue' => true]];
        }

        if ($data['time_to_first_token_ms'] ?? null) {
            $attributes[] = ['key' => 'spectra.time_to_first_token_ms', 'value' => ['intValue' => $data['time_to_first_token_ms']]];
        }

        if ($data['tokens_per_second'] ?? null) {
            $attributes[] = ['key' => 'spectra.tokens_per_second', 'value' => ['doubleValue' => $data['tokens_per_second']]];
        }

        if ($data['duration_seconds'] ?? null) {
            $attributes[] = ['key' => 'spectra.duration_seconds', 'value' => ['doubleValue' => $data['duration_seconds']]];
        }

        return $attributes;
    }

    /**
     * Build the span status.
     *
     * @param  array<string, mixed>  $data
     * @return array{code: int, message?: string}
     */
    protected function status(array $data): array
    {
        if ($data['is_failed'] ?? false) {
            return [
                'code' => 2, // STATUS_CODE_ERROR
                'message' => 'Request failed',
            ];
        }

        return [
            'code' => 1, // STATUS_CODE_OK
        ];
    }

    /**
     * Build span events (e.g. exceptions).
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    protected function events(array $data): array
    {
        return [];
    }

    /**
     * Convert a trace ID (UUID) to a hex string for OTLP.
     */
    protected function generateTraceId(?string $uuid): string
    {
        return str_replace('-', '', $uuid ?? '');
    }

    /**
     * Generate a random span ID.
     */
    protected function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Convert a Carbon timestamp to OTLP nanosecond string.
     */
    protected function toNano(mixed $timestamp): string
    {
        if ($timestamp instanceof \Illuminate\Support\Carbon) {
            return (string) ((int) $timestamp->getPreciseTimestamp(6) * 1000);
        }

        return '0';
    }
}
