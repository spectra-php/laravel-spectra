<?php

namespace Spectra\Actions\Media;

use Illuminate\Support\Facades\Storage;
use Spectra\Data\MediaContent;
use Spectra\Models\SpectraRequest;

class ResolveImage
{
    public function __invoke(string $id, int $index): MediaContent
    {
        $request = SpectraRequest::findOrFail($id);

        abort_unless($request->model_type === 'image', 404);

        // Try persisted media first
        /** @var array<int|string, string> $media */
        $media = $request->media_storage_path ?? [];

        if (isset($media[$index])) {
            $path = $media[$index];
            $disk = config('spectra.storage.media.disk', 'local');
            $content = Storage::disk($disk)->get($path);

            abort_unless((bool) $content, 404);

            return new MediaContent(
                content: $content,
                mime_type: $this->mimeType($path),
                filename: $request->id.'.'.$this->extension($path),
            );
        }

        // Fallback: extract base64 from raw response
        $base64 = $this->extractBase64($request->response ?? [], $index);

        abort_unless((bool) $base64, 404);

        return new MediaContent(
            content: base64_decode($base64),
            mime_type: 'image/png',
            filename: $request->id.'.png',
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function extractBase64(array $response, int $index): ?string
    {
        // Responses API: output[].result where type === 'image_generation_call'
        /** @var array<int, array<string, mixed>> $outputItems */
        $outputItems = $response['output'] ?? [];
        $imageItems = collect($outputItems)
            ->filter(fn (array $item) => ($item['type'] ?? '') === 'image_generation_call' && ! empty($item['result']))
            ->values();

        if ($imageItems->isNotEmpty()) {
            $value = $imageItems[$index]['result'] ?? null;

            return $value !== '[stripped]' ? $value : null;
        }

        // DALL-E format: data[].b64_json
        $value = $response['data'][$index]['b64_json'] ?? null;

        return $value !== '[stripped]' ? $value : null;
    }

    private function mimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/png',
        };
    }

    private function extension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'png';
    }
}
