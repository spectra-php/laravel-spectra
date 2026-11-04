<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Spectra\Actions\Media\ResolveAudio;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/requests/{id}/audio/{download?}: a request's text-to-speech audio,
 * played inline or downloaded.
 */
class ServeAudioController extends BaseApiController
{
    /**
     * Serve the request's text-to-speech audio inline, or as a download when asked.
     */
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
