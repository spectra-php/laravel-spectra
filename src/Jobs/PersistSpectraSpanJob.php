<?php

declare(strict_types=1);

namespace Spectra\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spectra\Support\Tracking\SpanWriter;

/**
 * Saves a finished span off the request cycle (queue or after-response).
 */
class PersistSpectraSpanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public readonly array $attributes,
    ) {}

    public function handle(SpanWriter $writer): void
    {
        $writer->save($this->attributes);
    }
}
