<?php

namespace Spectra\Http\Controllers\Api;

use Spectra\Actions\Media\ResolveAudio;
use Symfony\Component\HttpFoundation\Response;

class ServeAudioController extends BaseApiController
{
    public function __invoke(string $id, ?string $action, ResolveAudio $resolveAudio): Response
    {
        $media = $resolveAudio($id);

        $headers = [
            'Content-Type' => $media->mime_type,
            'Cache-Control' => 'private, max-age=86400',
        ];

        if ($action === 'download') {
            $headers['Content-Disposition'] = "attachment; filename=\"{$media->filename}\"";
        }

        return response($media->content, 200, $headers);
    }
}
