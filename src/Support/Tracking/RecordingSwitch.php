<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

use Illuminate\Support\Facades\Cache;

/**
 * Pauses and resumes recording without a deploy: a flag in the default cache
 * store that the request persister, the span writer, the score recorder and
 * media storage check before they write anything.
 *
 * The flag lives in the cache so every web server and queue worker sees it;
 * with a per-process store (array) a pause only reaches the process that set it.
 * A cache failure reads as "recording", so a broken cache never stops tracking
 * silently.
 *
 * Each request or job reads the flag once, not on every write; a long-running
 * process reads it again after REFRESH_SECONDS. Bound as scoped, so queue
 * workers and Octane start each job and request with a fresh read.
 */
class RecordingSwitch
{
    public const CACHE_KEY = 'spectra:recording-paused';

    /** How long a read of the flag is trusted, in seconds. */
    protected const REFRESH_SECONDS = 5;

    /** The flag as last read, or null before the first read. */
    protected ?bool $paused = null;

    /** When the flag was last read (microtime). */
    protected float $readAt = 0.0;

    /**
     * Stop storing new requests, spans, scores and media until resume() is called.
     */
    public static function pause(): void
    {
        Cache::forever(self::CACHE_KEY, true);

        static::current()->remember(true);
    }

    /**
     * Store new requests, spans, scores and media again.
     */
    public static function resume(): void
    {
        Cache::forget(self::CACHE_KEY);

        static::current()->remember(false);
    }

    /**
     * Determine whether recording is paused. With $fresh the cache is read
     * now, as the dashboard does to show the setting.
     */
    public static function isPaused(bool $fresh = false): bool
    {
        return static::current()->read($fresh);
    }

    /**
     * The switch of the current request or job.
     */
    protected static function current(): self
    {
        return app(self::class);
    }

    /**
     * The flag, read from the cache when it hasn't been yet, is stale, or $fresh is asked for.
     */
    protected function read(bool $fresh): bool
    {
        if ($fresh || $this->paused === null || microtime(true) - $this->readAt >= self::REFRESH_SECONDS) {
            $this->remember((bool) rescue(fn () => Cache::get(self::CACHE_KEY, false), false, report: false));
        }

        return (bool) $this->paused;
    }

    /**
     * Keep the flag as just read or set.
     */
    protected function remember(bool $paused): void
    {
        $this->paused = $paused;
        $this->readAt = microtime(true);
    }
}
