<?php

declare(strict_types=1);

namespace Spectra\Integrations\OpenTelemetry;

use Carbon\CarbonInterface;
use Spectra\Contracts\SpanBuilder;

/**
 * Default OpenTelemetry span builder.
 *
 * Builds OTLP-compatible spans following the OpenTelemetry semantic conventions
 * for GenAI client inference spans. Override protected methods for partial
 * customization, or implement SpanBuilder from scratch for full control.
 *
 * @see https://github.com/open-telemetry/semantic-conventions-genai/blob/main/docs/gen-ai/client-inference.md
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
            // Set when the incoming request's `traceparent` started this trace.
            'parentSpanId' => (string) ($data['parent_span_id'] ?? ''),
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
     * Spectra provider slugs mapped to `gen_ai.provider.name` well-known values.
     *
     * @var array<string, string>
     */
    protected const PROVIDER_NAMES = [
        'google' => 'gcp.gemini',
        'xai' => 'x_ai',
    ];

    /**
     * Build the span name: "{gen_ai.operation.name} {gen_ai.request.model}"
     * (e.g. "chat gpt-4o"), as the GenAI conventions recommend.
     *
     * @param  array<string, mixed>  $data
     */
    protected function spanName(array $data): string
    {
        return trim($this->operationName($data).' '.$this->requestModel($data));
    }

    /**
     * Build the span attributes array, following the OpenTelemetry GenAI
     * semantic conventions (gen_ai.*) plus Spectra's own spectra.* fields.
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
            $this->string('gen_ai.operation.name', $this->operationName($data)),
            $this->string('gen_ai.provider.name', self::PROVIDER_NAMES[$data['provider']] ?? (string) $data['provider']),
            $this->string('gen_ai.request.model', $this->requestModel($data)),
            $this->int('gen_ai.usage.input_tokens', (int) ($data['prompt_tokens'] ?? 0)),
            $this->int('gen_ai.usage.output_tokens', (int) ($data['completion_tokens'] ?? 0)),

            $this->string('spectra.trace_id', (string) ($data['trace_id'] ?? '')),
            $this->string('spectra.request_id', (string) $data['id']),
            $this->string('spectra.model', (string) $data['model']),
            $this->double('spectra.cost_cents', (float) ($data['total_cost_in_cents'] ?? 0)),
            $this->int('spectra.latency_ms', (int) ($data['latency_ms'] ?? 0)),

            $this->int('http.response.status_code', (int) ($data['status_code'] ?? 0)),
        ];

        if ($data['snapshot'] ?? null) {
            $attributes[] = $this->string('gen_ai.response.model', (string) $data['snapshot']);
        }

        if ($data['response_id'] ?? null) {
            $attributes[] = $this->string('gen_ai.response.id', (string) $data['response_id']);
        }

        if ($data['finish_reason'] ?? null) {
            $attributes[] = [
                'key' => 'gen_ai.response.finish_reasons',
                'value' => ['arrayValue' => ['values' => [['stringValue' => (string) $data['finish_reason']]]]],
            ];
        }

        if (($data['cached_tokens'] ?? 0) > 0) {
            $attributes[] = $this->int('gen_ai.usage.cache_read.input_tokens', (int) $data['cached_tokens']);
        }

        if (($data['cache_write_tokens'] ?? 0) > 0) {
            $attributes[] = $this->int('gen_ai.usage.cache_write.input_tokens', (int) $data['cache_write_tokens']);
        }

        if (($data['reasoning_tokens'] ?? 0) > 0) {
            $attributes[] = $this->int('gen_ai.usage.reasoning.output_tokens', (int) $data['reasoning_tokens']);
        }

        if ($data['is_streaming'] ?? false) {
            $attributes[] = ['key' => 'gen_ai.request.stream', 'value' => ['boolValue' => true]];
        }

        if ($data['conversation_id'] ?? null) {
            $attributes[] = $this->string('gen_ai.conversation.id', (string) $data['conversation_id']);
        }

        if ($data['is_failed'] ?? false) {
            $attributes[] = $this->string('error.type', (string) ($data['error_type'] ?? ($data['status_code'] ?? '_OTHER')));
        }

        if ($data['model_type'] ?? null) {
            $attributes[] = $this->string('spectra.model_type', (string) $data['model_type']);
        }

        if ($data['endpoint'] ?? null) {
            $attributes[] = $this->string('spectra.endpoint', (string) $data['endpoint']);
        }

        if ($data['pricing_tier'] ?? null) {
            $attributes[] = $this->string('spectra.pricing_tier', (string) $data['pricing_tier']);
        }

        if ($data['currency'] ?? null) {
            $attributes[] = $this->string('spectra.currency', (string) $data['currency']);
        }

        if (($data['trackable_type'] ?? null) && ($data['trackable_id'] ?? null)) {
            $attributes[] = $this->string('spectra.trackable.type', (string) $data['trackable_type']);
            $attributes[] = $this->string('spectra.trackable.id', (string) $data['trackable_id']);
        }

        if (($data['is_reasoning'] ?? false) && ($data['reasoning_effort'] ?? null)) {
            $attributes[] = $this->string('spectra.reasoning_effort', (string) $data['reasoning_effort']);
        }

        if ($data['has_tool_calls'] ?? false) {
            $attributes[] = ['key' => 'spectra.has_tool_calls', 'value' => ['boolValue' => true]];
        }

        if ($data['time_to_first_token_ms'] ?? null) {
            $attributes[] = $this->int('spectra.time_to_first_token_ms', (int) $data['time_to_first_token_ms']);
        }

        if ($data['tokens_per_second'] ?? null) {
            $attributes[] = $this->double('spectra.tokens_per_second', (float) $data['tokens_per_second']);
        }

        if ($data['duration_seconds'] ?? null) {
            $attributes[] = $this->double('spectra.duration_seconds', (float) $data['duration_seconds']);
        }

        return $attributes;
    }

    /**
     * `gen_ai.operation.name`: a well-known value where one applies.
     *
     * @param  array<string, mixed>  $data
     */
    protected function operationName(array $data): string
    {
        $endpoint = (string) ($data['endpoint'] ?? '');

        return match (true) {
            ($data['model_type'] ?? null) === 'embedding' => 'embeddings',
            str_contains($endpoint, 'rerank') => 'rerank',
            str_contains($endpoint, 'generateContent') || str_contains($endpoint, 'GenerateContent') => 'generate_content',
            (bool) preg_match('#/v1/completions$#', $endpoint) => 'text_completion',
            in_array($data['model_type'] ?? null, ['image', 'video', 'tts'], true) => 'generate_content',
            ($data['model_type'] ?? null) === 'stt' => 'transcription',
            default => 'chat',
        };
    }

    /**
     * The model id the request ran on. The `model` column holds the catalog
     * display name, so the provider's model id (snapshot) is preferred.
     *
     * @param  array<string, mixed>  $data
     */
    protected function requestModel(array $data): string
    {
        return (string) ($data['snapshot'] ?? null ?: $data['model']);
    }

    /**
     * @return array{key: string, value: array{stringValue: string}}
     */
    protected function string(string $key, string $value): array
    {
        return ['key' => $key, 'value' => ['stringValue' => $value]];
    }

    /**
     * @return array{key: string, value: array{intValue: int}}
     */
    protected function int(string $key, int $value): array
    {
        return ['key' => $key, 'value' => ['intValue' => $value]];
    }

    /**
     * @return array{key: string, value: array{doubleValue: float}}
     */
    protected function double(string $key, float $value): array
    {
        return ['key' => $key, 'value' => ['doubleValue' => $value]];
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
                'message' => (string) ($data['error_message'] ?? null ?: 'Request failed'),
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
        if ($timestamp instanceof CarbonInterface) {
            return (string) ((int) $timestamp->getPreciseTimestamp(6) * 1000);
        }

        return '0';
    }
}
