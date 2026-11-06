<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Illuminate\Http\Response;
use Spectra\Actions\Media\ResolveImage;

/**
 * GET /api/requests/{id}/images/{index}: one generated image of a request,
 * cached privately by the browser.
 */
class ServeImageController extends BaseApiController
{
    /**
     * Serve one generated image of the request, cached privately by the browser.
     */
    public function __invoke(string $id, int $index, ResolveImage $resolveImage): Response
    {
        $media = $resolveImage($id, $index);

        return response($media->content, 200, [
            'Content-Type' => $media->mime_type,
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
