<?php

namespace Spectra\Integrations\OpenTelemetry;

use Spectra\Contracts\SpanBuilder;

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

    /** @var array<string, string> */
    protected array $resourceAttributes = [];

    protected SpanBuilder $spanBuilder;

    /**
     * @param  array<string, string>  $resourceAttributes
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

    protected function getSpectraVersion(): string
    {
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                return \Composer\InstalledVersions::getVersion('spectra-php/laravel-spectra') ?? '1.0.0';
            } catch (\Exception) {
                return '1.0.0';
            }
        }

        return '1.0.0';
    }
}
