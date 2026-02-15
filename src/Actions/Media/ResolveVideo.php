<?php

namespace Spectra\Actions\Media;

use Illuminate\Support\Facades\Storage;
use Spectra\Models\SpectraRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResolveVideo
{
    public function __construct(
        private readonly StreamOpenAiVideo $streamOpenAiVideo,
    ) {}

    public function __invoke(string $id): StreamedResponse
    {
        $request = SpectraRequest::findOrFail($id);

        abort_unless($request->model_type === 'video', 404);
        abort_if($request->expires_at && $request->expires_at->isPast(), 410, 'Video has expired.');

        // Serve from persisted media storage (Google Veo, xAI, Replicate, etc.)
        $media = $request->media_storage_path ?? [];

        if (! empty($media)) {
            /** @var string $firstMediaPath */
            $firstMediaPath = $media[array_key_first($media)];

            return $this->streamFromStorage($firstMediaPath, $id);
        }

        // OpenAI Sora: stream from API
        if ($request->provider === 'openai') {
            $videoId = $request->response['id'] ?? null;
            abort_unless((bool) $videoId, 404);

            return ($this->streamOpenAiVideo)($videoId);
        }

        abort(404, 'Video not available.');
    }

    private function streamFromStorage(string $path, string $requestId): StreamedResponse
    {
        $disk = config('spectra.storage.media.disk', 'local');

        abort_unless(Storage::disk($disk)->exists($path), 404);

        $stream = Storage::disk($disk)->readStream($path);

        abort_unless($stream !== null, 404);

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => 'video/mp4',
            'Content-Disposition' => "attachment; filename=\"{$requestId}.mp4\"",
        ]);
    }
}
