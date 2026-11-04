<?php

declare(strict_types=1);

namespace Spectra\Integrations\OpenTelemetry;

use Carbon\CarbonInterface;
use Composer\InstalledVersions;
use Spectra\Contracts\SpanBuilder;
use Spectra\Enums\SpanKind;
use Spectra\Enums\SpanStatus;

/**
 * OpenTelemetry span exporter — coordinates span building and OTLP envelope wrapping.
 *
 * Delegates individual span construction to the injected SpanBuilder contract,
 * allowing users to customize how requests become spans. Owns the OTLP batch
 * envelope (resource attributes, scope metadata) which applies uniformly.
 */
class SpanExporter
{
    protected string $serviceName;

    protected string $serviceVersion;

    /** @var array<string, scalar> */
    protected array $resourceAttributes = [];

    protected SpanBuilder $spanBuilder;

    /**
     * Create a new span exporter instance for the given service.
     *
     * @param  array<string, scalar>  $resourceAttributes
     */
    public function __construct(
        ?string $serviceName = null,
        ?string $serviceVersion = null,
        array $resourceAttributes = [],
        ?SpanBuilder $spanBuilder = null
    ) {
        $this->serviceName = $serviceName ?? config('app.name', 'laravel-app');
        $this->serviceVersion = $serviceVersion ?? config('spectra.integrations.opentelemetry.service_version', '1.0.0');
        $this->resourceAttributes = $resourceAttributes;
        $this->spanBuilder = $spanBuilder ?? new DefaultSpanBuilder;
    }

    /**
     * Create a span from transformed request data.
     *
     * @param  array<string, mixed>  $data  Transformed request data from RequestTransformer
     * @return array<string, mixed>
     */
    public function createSpan(array $data): array
    {
        return $this->spanBuilder->build($data);
    }

    /**
     * An OTLP span for a stored span that is not an AI call: a
     * Spectra::span() block or the request, job or command of a trace.
     *
     * @param  array<string, mixed>  $span  The span's columns, dates as dates
     * @return array<string, mixed>
     */
    public function createSpectraSpan(array $span): array
    {
        $kind = SpanKind::tryFrom((string) ($span['kind'] ?? '')) ?? SpanKind::Span;
        $failed = ($span['status'] ?? null) === SpanStatus::Error->value;

        $attributes = [
            ['key' => 'spectra.span.kind', 'value' => ['stringValue' => $kind->value]],
        ];

        if (filled($span['origin'] ?? null)) {
            $attributes[] = ['key' => 'spectra.origin', 'value' => ['stringValue' => (string) $span['origin']]];
        }

        foreach ((array) ($span['attributes'] ?? []) as $key => $value) {
            $attributes[] = ['key' => (string) $key, 'value' => $this->attributeValue($value)];
        }

        return [
            'traceId' => str_replace('-', '', (string) $span['trace_id']),
            'spanId' => (string) $span['span_id'],
            'parentSpanId' => (string) ($span['parent_span_id'] ?? ''),
            'name' => (string) $span['name'],
            'kind' => $kind->otlpKind(),
            'startTimeUnixNano' => $this->toNano($span['started_at'] ?? null),
            'endTimeUnixNano' => $this->toNano($span['ended_at'] ?? null),
            'attributes' => $attributes,
            'status' => $failed
                ? ['code' => 2, 'message' => (string) ($span['error_message'] ?? null ?: 'Span failed')]
                : ['code' => 1],
            'events' => [],
            'links' => [],
        ];
    }

    /**
     * An OTLP attribute value for a span attribute the app set.
     *
     * @return array<string, mixed>
     */
    protected function attributeValue(mixed $value): array
    {
        return match (true) {
            is_bool($value) => ['boolValue' => $value],
            is_int($value) => ['intValue' => $value],
            is_float($value) => ['doubleValue' => $value],
            is_string($value) => ['stringValue' => $value],
            default => ['stringValue' => (string) json_encode($value)],
        };
    }

    /**
     * Convert a timestamp to Unix nanoseconds as OTLP expects, or '0' when it is missing.
     */
    protected function toNano(mixed $timestamp): string
    {
        return $timestamp instanceof CarbonInterface
            ? (string) ((int) $timestamp->getPreciseTimestamp(6) * 1000)
            : '0';
    }

    /**
     * Wrap spans in an OTLP-compliant batch envelope.
     *
     * @param  array<int, array<string, mixed>>  $spans
     * @return array<string, mixed>
     */
    public function exportBatch(array $spans): array
    {
        return [
            'resourceSpans' => [
                [
                    'resource' => [
                        'attributes' => $this->buildResourceAttributes(),
                    ],
                    'scopeSpans' => [
                        [
                            'scope' => [
                                'name' => 'laravel-spectra',
                                'version' => $this->getSpectraVersion(),
                            ],
                            'spans' => $spans,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Build the OTLP resource attributes that describe the service and environment.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildResourceAttributes(): array
    {
        $attributes = [
            ['key' => 'service.name', 'value' => ['stringValue' => $this->serviceName]],
            ['key' => 'service.version', 'value' => ['stringValue' => $this->serviceVersion]],
            ['key' => 'telemetry.sdk.name', 'value' => ['stringValue' => 'laravel-spectra']],
            ['key' => 'telemetry.sdk.language', 'value' => ['stringValue' => 'php']],
            ['key' => 'telemetry.sdk.version', 'value' => ['stringValue' => $this->getSpectraVersion()]],
        ];

        $environment = config('app.env', 'production');
        $attributes[] = ['key' => 'deployment.environment', 'value' => ['stringValue' => $environment]];

        foreach ($this->resourceAttributes as $key => $value) {
            $attributes[] = ['key' => $key, 'value' => ['stringValue' => (string) $value]];
        }

        return $attributes;
    }

    /**
     * Get the installed Spectra version for the telemetry SDK attribute.
     */
    protected function getSpectraVersion(): string
    {
        if (class_exists(InstalledVersions::class)) {
            try {
                return InstalledVersions::getVersion('spectra-php/laravel-spectra') ?? '1.0.0';
            } catch (\Exception) {
                return '1.0.0';
            }
        }

        return '1.0.0';
    }
}
