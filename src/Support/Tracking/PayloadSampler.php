<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

/**
 * Decides whether a trace's payloads are stored, by `spectra.storage.sampling`:
 * request and response bodies, and the arguments and results of its tools.
 * The choice is made per trace from its id, so every call and tool of a
 * trace keeps its payload, or none does. Failures keep theirs unless
 * `keep_failures` is off.
 */
class PayloadSampler
{
    /**
     * Determine if a payload of this trace is stored.
     */
    public function keeps(?string $traceId, bool $failed = false): bool
    {
        $rate = (float) config('spectra.storage.sampling.rate', 1.0);

        if ($rate >= 1.0) {
            return true;
        }

        if ($failed && config('spectra.storage.sampling.keep_failures', true)) {
            return true;
        }

        return $rate > 0.0 && (crc32((string) $traceId) % 10_000) < $rate * 10_000;
    }
}
