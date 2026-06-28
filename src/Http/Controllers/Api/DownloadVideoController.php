<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Spectra\Actions\Media\ResolveVideo;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadVideoController extends BaseApiController
{
    public function __invoke(string $id, ResolveVideo $action): StreamedResponse
    {
        return $action($id);
    }
}
