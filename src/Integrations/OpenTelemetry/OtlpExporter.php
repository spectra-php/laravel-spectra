<?php

declare(strict_types=1);

namespace Spectra\Integrations\OpenTelemetry;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spectra\Contracts\RequestExporter;

/**
 * Sends tracked requests to an OpenTelemetry collector as OTLP/HTTP JSON spans.
 *
 * Uses Laravel's Http client instead of the OpenTelemetry SDK, so exporting
 * adds no dependency. A failed export is logged and never reaches the app.
 */
class OtlpExporter implements RequestExporter
{
    protected string $endpoint;

    /** @var array<string, string> */
    protected array $headers;

    protected SpanExporter $spanExporter;

    /**
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

    public function export(array $data): void
    {
        if (! config('spectra.integrations.opentelemetry.enabled', false)) {
            return;
        }

        $span = $this->spanExporter->createSpan($data);
        $payload = $this->spanExporter->exportBatch([$span]);

        $this->send($payload);
    }

    public function exportBatch(array $requests): void
    {
        if (! config('spectra.integrations.opentelemetry.enabled', false)) {
            return;
        }

        $spans = array_map(
            fn (array $data) => $this->spanExporter->createSpan($data),
            $requests
        );

        $payload = $this->spanExporter->exportBatch($spans);

        $this->send($payload);
    }

    /**
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

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function setEndpoint(string $endpoint): self
    {
        $this->endpoint = $endpoint;

        return $this;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function withBearerToken(string $token): self
    {
        $this->headers['Authorization'] = 'Bearer '.$token;

        return $this;
    }

    public function withApiKey(string $key, string $headerName = 'x-api-key'): self
    {
        $this->headers[$headerName] = $key;

        return $this;
    }
}
