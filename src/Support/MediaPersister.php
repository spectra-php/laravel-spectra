<?php

declare(strict_types=1);

namespace Spectra\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Writes generated media (images, audio, video) to the configured media disk
 * under one folder per request, so the dashboard can serve it after the
 * provider's own copy expires. Handlers store the real file extension; the
 * dashboard derives the mime type from it.
 */
class MediaPersister
{
    public function store(string $requestId, int $index, string $content, string $extension): string
    {
        $disk = config('spectra.storage.media.disk', 'local');
        $basePath = config('spectra.storage.media.path', 'spectra-media');
        $path = "{$basePath}/{$requestId}/{$index}.{$extension}";

        Storage::disk($disk)->put($path, $content);

        return $path;
    }
}
