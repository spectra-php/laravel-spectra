<?php

declare(strict_types=1);

namespace Spectra\Integrations\OpenTelemetry;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spectra\Contracts\ExportsSpans;
use Spectra\Contracts\RequestExporter;

/**
 * Sends tracked requests, and the spans around them, to an OpenTelemetry
 * collector as OTLP/HTTP JSON spans.
 *
 * Uses Laravel's Http client instead of the OpenTelemetry SDK, so exporting
 * adds no dependency. A failed export is logged and never reaches the app.
 */
class OtlpExporter implements ExportsSpans, RequestExporter
{
    protected string $endpoint;

    /** @var array<string, string> */
    protected array $headers;

    protected SpanExporter $spanExporter;

    /**
     * Create a new OTLP exporter instance, reading the endpoint and headers from config.
     *
     * @param  array<string, string>  $headers
     */
    public function __construct(
        ?string $endpoint = null,
        array $headers = [],
        ?SpanExporter $spanExporter = null
    ) {
        $this->endpoint = $endpoint ?? config('spectra.integrations.opentelemetry.endpoint', 'http://localhost:4318/v1/traces');
        $this->headers = array_merge([
            'Content-Type' => 'application/json',
        ], $headers, config('spectra.integrations.opentelemetry.headers', []));
        $this->spanExporter = $spanExporter ?? new SpanExporter;
    }

    /**
     * Export a tracked request to the collector as a single span.
     */
    public function export(array $data): void
    {
        if (! config('spectra.integrations.opentelemetry.enabled', false)) {
            return;
        }

        $span = $this->spanExporter->createSpan($data);
        $payload = $this->spanExporter->exportBatch([$span]);

        $this->send($payload);
    }

    /**
     * Export a stored Spectra span to the collector.
     */
    public function exportSpan(array $span): void
    {
        if (! config('spectra.integrations.opentelemetry.enabled', false)) {
            return;
        }

        $this->send($this->spanExporter->exportBatch([$this->spanExporter->createSpectraSpan($span)]));
    }

    /**
     * Export several tracked requests to the collector in one OTLP payload.
     */
    public function exportBatch(array $requests): void
    {
        if (! config('spectra.integrations.opentelemetry.enabled', false)) {
            return;
        }

        $spans = Arr::map(
            $requests,
            fn (array $data) => $this->spanExporter->createSpan($data),
        );

        $payload = $this->spanExporter->exportBatch($spans);

        $this->send($payload);
    }

    /**
     * Post an OTLP payload to the collector, logging a failure instead of throwing.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function send(array $payload): bool
    {
        try {
            $response = Http::withHeaders($this->headers)
                ->timeout($this->timeout())
                ->post($this->endpoint, $payload);

            if ($response->failed()) {
                Log::warning('Failed to export OTLP traces', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('OTLP export error: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Published configs read SPECTRA_OTEL_TIMEOUT without a cast, so the
     * value can arrive as a string, which Http::timeout() rejects.
     */
    protected function timeout(): float
    {
        $timeout = config('spectra.integrations.opentelemetry.timeout', 10);

        if (! is_numeric($timeout) || $timeout <= 0) {
            return 10.0;
        }

        return (float) $timeout;
    }

    /**
     * Get the collector endpoint spans are posted to.
     */
    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * Set the collector endpoint spans are posted to.
     */
    public function setEndpoint(string $endpoint): self
    {
        $this->endpoint = $endpoint;

        return $this;
    }

    /**
     * Add a header to every export request.
     */
    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * Authenticate exports to the collector with a bearer token.
     */
    public function withBearerToken(string $token): self
    {
        $this->headers['Authorization'] = 'Bearer '.$token;

        return $this;
    }

    /**
     * Authenticate exports to the collector with an API key header.
     */
    public function withApiKey(string $key, string $headerName = 'x-api-key'): self
    {
        $this->headers[$headerName] = $key;

        return $this;
    }
}
