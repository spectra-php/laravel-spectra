<?php

declare(strict_types=1);

namespace Spectra\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Spectra\Contracts\RequestExporter;

/**
 * Sends one tracked request to the bound RequestExporter (OpenTelemetry by
 * default) off the request cycle, on the queue or after the response, so a
 * slow collector never delays the user's request.
 */
class ExportTrackedRequestJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new export job instance.
     *
     * @param  array<string, mixed>  $data  Transformed request data from RequestTransformer
     */
    public function __construct(
        public readonly array $data,
    ) {}

    /**
     * Send the tracked request to the configured exporter.
     */
    public function handle(RequestExporter $exporter): void
    {
        $exporter->export($this->data);
    }
}
