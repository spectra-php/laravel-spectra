<?php

namespace Spectra\Support;

/**
 * Extracts audio duration from binary audio data using getID3.
 *
 * This class requires the optional `james-heinrich/getid3` package.
 * When getID3 is not installed, duration extraction is silently skipped.
 *
 * Install with: composer require james-heinrich/getid3
 */
class AudioDurationExtractor
{
    /**
     * Extract the duration in seconds from raw audio binary data.
     *
     * Writes the data to a temporary file, analyzes it with getID3,
     * then cleans up. Returns null if getID3 is not installed or
     * if the duration cannot be determined.
     */
    public function extract(string $rawAudioData): ?float
    {
        $getId3Class = self::resolveGetId3Class();

        if ($getId3Class === null) {
            return null;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'spectra_audio_');

        if ($tempFile === false) {
            return null;
        }

        try {
            if (file_put_contents($tempFile, $rawAudioData) === false) {
                return null;
            }

            $getID3 = new $getId3Class;
            /** @var array<string, mixed> $fileInfo */
            $fileInfo = $getID3->analyze($tempFile); // @phpstan-ignore method.notFound

            return isset($fileInfo['playtime_seconds'])
                ? (float) $fileInfo['playtime_seconds']
                : null;
        } catch (\Throwable) {
            return null;
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Check if getID3 is available.
     */
    public static function isAvailable(): bool
    {
        return self::resolveGetId3Class() !== null;
    }

    protected static function resolveGetId3Class(): ?string
    {
        if (class_exists(\JamesHeinrich\GetID3\GetID3::class)) {
            return \JamesHeinrich\GetID3\GetID3::class;
        }

        return class_exists(\getID3::class) ? \getID3::class : null;
    }
}
