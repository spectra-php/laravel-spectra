<?php

declare(strict_types=1);

namespace Spectra\Actions\Tags;

use Spectra\Data\Responses\TagsResponse;
use Spectra\Data\Tag;
use Spectra\Models\SpectraTag;

class GetTags
{
    public function __invoke(): TagsResponse
    {
        return new TagsResponse(
            tags: Tag::fromCollection(
                SpectraTag::withCount('requests')
                    ->orderByDesc('requests_count')
                    ->get()
            ),
        );
    }
}
