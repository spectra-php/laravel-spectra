<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Spectra\Actions\Media\ResolveVideo;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /api/requests/{id}/video: downloads a request's generated video.
 */
class DownloadVideoController extends BaseApiController
{
    /**
     * Stream the request's generated video as a download.
     */
    public function __invoke(string $id, ResolveVideo $resolveVideo): StreamedResponse
    {
        return $resolveVideo($id);
    }
}
