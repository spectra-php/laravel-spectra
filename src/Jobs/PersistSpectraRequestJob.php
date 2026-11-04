<?php

declare(strict_types=1);

namespace Spectra\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Spectra\Support\Tracking\RequestWriter;

/**
 * Saves a tracked request off the request cycle (queue or after-response).
 */
class PersistSpectraRequestJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new persist request job instance.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $tags
     */
    public function __construct(
        public readonly array $attributes,
        public readonly array $tags,
    ) {}

    /**
     * Write the tracked request and its tags through the request writer.
     */
    public function handle(RequestWriter $writer): void
    {
        $writer->write($this->attributes, $this->tags);
    }
}
