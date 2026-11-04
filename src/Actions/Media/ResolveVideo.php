<?php

declare(strict_types=1);

namespace Spectra\Actions\Media;

use Illuminate\Support\Facades\Storage;
use Spectra\Models\SpectraRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The generated video of a request as a download stream: from the media disk
 * when it was persisted, or straight from OpenAI for Sora videos, which
 * OpenAI keeps until they expire.
 */
class ResolveVideo
{
    /**
     * Create a new resolve video action instance.
     */
    public function __construct(
        private readonly StreamOpenAiVideo $streamOpenAiVideo,
    ) {}

    /**
     * Get a download stream of the request's generated video.
     */
    public function __invoke(string $id): StreamedResponse
    {
        $request = SpectraRequest::query()->findOrFail($id);

        abort_unless($request->model_type === 'video', 404);
        abort_if($request->expires_at && $request->expires_at->isPast(), 410, 'Video has expired.');

        // Serve from persisted media storage (Google Veo, xAI, Replicate, etc.)
        $media = $request->media_storage_path ?? [];

        if ($media !== []) {
            /** @var string $firstMediaPath */
            $firstMediaPath = $media[array_key_first($media)];

            return $this->streamFromStorage($firstMediaPath, $id);
        }

        // OpenAI Sora: stream from API
        if ($request->provider === 'openai') {
            $videoId = $request->response['id'] ?? null;
            abort_unless(filled($videoId), 404);

            return ($this->streamOpenAiVideo)($videoId);
        }

        abort(404, 'Video not available.');
    }

    /**
     * Stream a persisted video from the media disk with the mime type of its extension.
     */
    private function streamFromStorage(string $path, string $requestId): StreamedResponse
    {
        $disk = config('spectra.storage.media.disk', 'local');

        abort_unless(Storage::disk($disk)->exists($path), 404);

        $stream = Storage::disk($disk)->readStream($path);

        abort_unless($stream !== null, 404);

        // Handlers store videos under their real extension (mp4, webm, mov, …).
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'mp4';
        $mime = match ($extension) {
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            default => 'video/mp4',
        };

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => "attachment; filename=\"{$requestId}.{$extension}\"",
        ]);
    }
}
