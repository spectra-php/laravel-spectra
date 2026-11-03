<?php

declare(strict_types=1);

/**
 * The OTLP exporter sends spans with the configured timeout, whatever type
 * the published config hands it.
 */

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spectra\Integrations\OpenTelemetry\OtlpExporter;
use Spectra\Models\SpectraRequest;
use Spectra\Support\RequestTransformer;

it('exports when the timeout comes from the environment as a string', function () {
    Http::fake(['collector.test/*' => Http::response()]);
    config([
        'spectra.integrations.opentelemetry.enabled' => true,
        'spectra.integrations.opentelemetry.timeout' => '5',
    ]);

    $data = app(RequestTransformer::class)->transform(SpectraRequest::factory()->make(['id' => 'req-1']));

    app(OtlpExporter::class)->setEndpoint('https://collector.test/v1/traces')->export($data);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://collector.test/v1/traces');
});
