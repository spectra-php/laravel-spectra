<?php

namespace Spectra\Http\Controllers\Api;

use Illuminate\Http\Response;
use Spectra\Actions\Media\ResolveImage;

class ServeImageController extends BaseApiController
{
    public function __invoke(string $id, int $index, ResolveImage $action): Response
    {
        $media = $action($id, $index);

        return response($media->content, 200, [
            'Content-Type' => $media->mime_type,
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
