<?php

declare(strict_types=1);

use Spectra\Integrations\OpenTelemetry\SpanExporter;

/**
 * The span exporter wraps spans in an OTLP batch envelope carrying the
 * service's resource attributes.
 */
it('wraps spans in an OTLP batch envelope with the service resource attributes', function () {
    $exporter = new SpanExporter('my-app', '2.0.0', ['deployment.region' => 'us-east-1']);

    $batch = $exporter->exportBatch([
        ['name' => 'span-1'],
        ['name' => 'span-2'],
    ]);

    $attrs = collect($batch['resourceSpans'][0]['resource']['attributes']);

    expect($batch['resourceSpans'])->toHaveCount(1)
        ->and($batch['resourceSpans'][0]['scopeSpans'][0]['scope']['name'])->toBe('laravel-spectra')
        ->and($batch['resourceSpans'][0]['scopeSpans'][0]['spans'])->toHaveCount(2)
        ->and($attrs->firstWhere('key', 'service.name')['value']['stringValue'])->toBe('my-app')
        ->and($attrs->firstWhere('key', 'service.version')['value']['stringValue'])->toBe('2.0.0')
        ->and($attrs->firstWhere('key', 'deployment.region')['value']['stringValue'])->toBe('us-east-1');
});
