<?php

declare(strict_types=1);

namespace Spectra\Actions\Media;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spectra\Data\MediaContent;
use Spectra\Models\SpectraRequest;

/**
 * One generated image of a request: from the media disk when it was
 * persisted, otherwise decoded from the base64 still in the stored response.
 */
class ResolveImage
{
    /**
     * Get one generated image of the request from the media disk or the stored response.
     */
    public function __invoke(string $id, int $index): MediaContent
    {
        $request = SpectraRequest::query()->findOrFail($id);

        abort_unless($request->model_type === 'image', 404);

        /** @var array<int|string, string> $media */
        $media = $request->media_storage_path ?? [];

        if (isset($media[$index])) {
            $path = $media[$index];
            $disk = config('spectra.storage.media.disk', 'local');
            $content = Storage::disk($disk)->get($path);

            abort_unless((bool) $content, 404);

            return new MediaContent(
                content: $content,
                mime_type: $this->resolveMimeType($path),
                filename: "{$request->id}.{$this->resolveExtension($path)}",
            );
        }

        $base64 = $this->extractBase64($request->response ?? [], $index);

        abort_unless((bool) $base64, 404);

        return new MediaContent(
            content: base64_decode($base64),
            mime_type: 'image/png',
            filename: "{$request->id}.png",
        );
    }

    /**
     * Extract the image's base64 from a Responses API or DALL-E response body.
     *
     * @param  array<string, mixed>  $response
     */
    private function extractBase64(array $response, int $index): ?string
    {
        // Responses API: output[].result where type === 'image_generation_call'
        $imageItems = collect(Arr::wrap($response['output'] ?? []))
            ->filter(fn (mixed $item) => is_array($item) && ($item['type'] ?? '') === 'image_generation_call' && filled($item['result'] ?? null))
            ->values();

        if ($imageItems->isNotEmpty()) {
            $value = $imageItems[$index]['result'] ?? null;

            return $value !== '[stripped]' ? $value : null;
        }

        // DALL-E format: data[].b64_json
        $value = $response['data'][$index]['b64_json'] ?? null;

        return $value !== '[stripped]' ? $value : null;
    }

    /**
     * Get the image mime type for the stored file's extension.
     */
    private function resolveMimeType(string $path): string
    {
        $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/png',
        };
    }

    /**
     * Get the stored file's extension, defaulting to png.
     */
    private function resolveExtension(string $path): string
    {
        return Str::lower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'png';
    }
}
