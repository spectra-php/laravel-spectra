<?php

namespace Spectra\Actions\Media;

use Illuminate\Support\Facades\Storage;
use Spectra\Data\MediaContent;
use Spectra\Models\SpectraRequest;

class ResolveAudio
{
    public function __invoke(string $id): MediaContent
    {
        $request = SpectraRequest::findOrFail($id);

        abort_unless($request->model_type === 'tts', 404);

        $media = $request->media_storage_path ?? [];

        abort_if(empty($media), 404, 'No audio file stored for this request.');

        /** @var string $path */
        $path = $media[array_key_first($media)];
        $disk = config('spectra.storage.media.disk', 'local');
        $content = Storage::disk($disk)->get($path);

        abort_unless((bool) $content, 404);

        return new MediaContent(
            content: $content,
            mime_type: $this->mimeType($path),
            filename: $request->id.'.'.$this->extension($path),
        );
    }

    private function mimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
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

    private function extension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'mp3';
    }
}
