<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

use Illuminate\Support\Facades\Cache;

/**
 * Pauses and resumes recording without a deploy: a flag in the default cache
 * store that the request persister, the span writer and media storage check
 * before they write anything.
 *
 * The flag lives in the cache so every web server and queue worker sees it;
 * with a per-process store (array) a pause only reaches the process that set it.
 * A cache failure reads as "recording", so a broken cache never stops tracking
 * silently.
 */
class RecordingSwitch
{
    public const CACHE_KEY = 'spectra:recording-paused';

    /**
     * Stop storing new requests, spans and media until resume() is called.
     */
    public static function pause(): void
    {
        Cache::forever(self::CACHE_KEY, true);
    }

    /**
     * Store new requests, spans and media again.
     */
    public static function resume(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Determine whether recording is paused.
     */
    public static function isPaused(): bool
    {
        return (bool) rescue(fn () => Cache::get(self::CACHE_KEY, false), false, report: false);
    }
}
