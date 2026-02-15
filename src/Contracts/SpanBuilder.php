<?php

namespace Spectra\Contracts;

/**
 * Contract for building OpenTelemetry spans from tracked request data.
 *
 * Implement this interface to customize how AI requests are represented
 * as OpenTelemetry spans — control span names, attributes, status, and events.
 *
 * The data array is produced by RequestTransformer and contains all
 * request metadata (provider, model, tokens, costs, performance, etc.).
 *
 * @see \Spectra\Integrations\OpenTelemetry\DefaultSpanBuilder
 * @see \Spectra\Support\RequestTransformer
 */
interface SpanBuilder
{
    /**
     * Build an OTLP-compatible span array from the tracked request data.
     *
     * @param  array<string, mixed>  $data  Transformed request data from RequestTransformer
     * @return array{traceId: string, spanId: string, parentSpanId: string, name: string, kind: int, startTimeUnixNano: string, endTimeUnixNano: string, attributes: array<int, array<string, mixed>>, status: array<string, mixed>, events: array<int, array<string, mixed>>, links: array<int, array<string, mixed>>}
     */
    public function build(array $data): array;
}
