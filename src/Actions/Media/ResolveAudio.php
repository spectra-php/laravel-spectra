<?php

declare(strict_types=1);

namespace Spectra\Actions\Media;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spectra\Data\MediaContent;
use Spectra\Models\SpectraRequest;

/**
 * The stored text-to-speech audio for one request, with its mime type and a
 * download name taken from the stored file's extension, never the client.
 */
class ResolveAudio
{
    /**
     * Get the stored text-to-speech audio of the request with its mime type and file name.
     */
    public function __invoke(string $id): MediaContent
    {
        $request = SpectraRequest::query()->findOrFail($id);

        abort_unless($request->model_type === 'tts', 404);

        $media = $request->media_storage_path ?? [];

        abort_if($media === [], 404, 'No audio file stored for this request.');

        /** @var string $path */
        $path = $media[array_key_first($media)];
        $disk = config('spectra.storage.media.disk', 'local');
        $content = Storage::disk($disk)->get($path);

        abort_unless((bool) $content, 404);

        return new MediaContent(
            content: $content,
            mime_type: $this->resolveMimeType($path),
            filename: "{$request->id}.{$this->resolveExtension($path)}",
        );
    }

    /**
     * Get the audio mime type for the stored file's extension.
     */
    private function resolveMimeType(string $path): string
    {
        $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'opus' => 'audio/opus',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            'pcm' => 'audio/pcm',
            'ogg' => 'audio/ogg',
            default => 'audio/mpeg',
        };
    }

    /**
     * Get the stored file's extension, defaulting to mp3.
     */
    private function resolveExtension(string $path): string
    {
        return Str::lower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'mp3';
    }
}
