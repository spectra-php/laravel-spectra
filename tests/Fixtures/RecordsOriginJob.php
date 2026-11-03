<?php

declare(strict_types=1);

namespace Spectra\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Spectra\Support\Tracking\RequestContext;

/**
 * Records the origin a tracked call would get while this job runs, then
 * does what its mode says.
 */
class RecordsOriginJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;

    /** @var array<int, string|null> */
    public static array $origins = [];

    public function __construct(public OriginJobMode $mode = OriginJobMode::Record) {}

    public function handle(): void
    {
        static::$origins[] = (new RequestContext)->origin;

        if ($this->mode === OriginJobMode::Nested) {
            dispatch(new self);

            static::$origins[] = (new RequestContext)->origin;
        }

        if ($this->mode === OriginJobMode::Fail) {
            throw new RuntimeException('The job failed.');
        }
    }
}
