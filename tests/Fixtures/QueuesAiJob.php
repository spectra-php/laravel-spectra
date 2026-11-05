<?php

declare(strict_types=1);

namespace Spectra\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A queued job that makes no AI call itself and queues one that does, like
 * a job that stores a message and queues its analysis. For the span tests.
 */
class QueuesAiJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;

    public function handle(): void
    {
        dispatch(new CallsAiJob);
    }
}
