<?php

declare(strict_types=1);

namespace Spectra\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;

/**
 * A queued job that makes one OpenAI chat call (or none), for the span
 * tests. Fake the OpenAI host before dispatching it.
 */
class CallsAiJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;

    public function __construct(public bool $callsAi = true) {}

    public function handle(): void
    {
        if ($this->callsAi) {
            Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']);
        }
    }
}
