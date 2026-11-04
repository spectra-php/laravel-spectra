<?php

declare(strict_types=1);

namespace Spectra\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Spectra\Support\Tracking\SpanWriter;

/**
 * Saves a finished span off the request cycle (queue or after-response).
 */
class PersistSpectraSpanJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new persist span job instance.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public readonly array $attributes,
    ) {}

    /**
     * Save the span through the span writer.
     */
    public function handle(SpanWriter $writer): void
    {
        $writer->save($this->attributes);
    }
}
