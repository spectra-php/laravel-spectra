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
     * Review queues to put the call's trace in. A plain property with a
     * default, so a job queued by an older version still runs.
     *
     * @var list<string>
     */
    public array $reviewQueues = [];

    /**
     * Create a new persist request job instance.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $tags
     * @param  list<string>  $reviewQueues
     */
    public function __construct(
        public readonly array $attributes,
        public readonly array $tags,
        array $reviewQueues = [],
    ) {
        $this->reviewQueues = $reviewQueues;
    }

    /**
     * Write the tracked request and its tags through the request writer.
     */
    public function handle(RequestWriter $writer): void
    {
        $writer->write($this->attributes, $this->tags, reviewQueues: $this->reviewQueues);
    }
}
