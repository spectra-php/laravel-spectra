<?php

namespace Spectra\Support;

use Illuminate\Support\Facades\Storage;

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
