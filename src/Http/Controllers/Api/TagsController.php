<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Spectra\Actions\Tags\GetTags;
use Spectra\Data\Responses\TagsResponse;

/**
 * GET /api/tags: every tag with its request count.
 */
class TagsController extends BaseApiController
{
    /**
     * Get every tag with its request count.
     */
    public function __invoke(GetTags $action): TagsResponse
    {
        return $action();
    }
}
