<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Spectra\Actions\Tags\GetTags;
use Spectra\Data\Responses\TagsResponse;

class TagsController extends BaseApiController
{
    public function __invoke(GetTags $action): TagsResponse
    {
        return $action();
    }
}
