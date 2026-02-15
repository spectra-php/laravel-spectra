<?php

namespace Spectra\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spectra\Contracts\RequestExporter;

class ExportTrackedRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $data  Transformed request data from RequestTransformer
     */
    public function __construct(
        public readonly array $data,
    ) {}

    public function handle(RequestExporter $exporter): void
    {
        $exporter->export($this->data);
    }
}
