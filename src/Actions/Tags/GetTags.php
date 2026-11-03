<?php

declare(strict_types=1);

namespace Spectra\Actions\Tags;

use Spectra\Data\Responses\TagsResponse;
use Spectra\Data\Tag;
use Spectra\Models\SpectraTag;

/**
 * Every tag with how many requests carry it, most used first.
 */
class GetTags
{
    public function __invoke(): TagsResponse
    {
        return new TagsResponse(
            tags: Tag::fromCollection(
                SpectraTag::query()->withCount('requests')
                    ->orderByDesc('requests_count')
                    ->get()
            ),
        );
    }
}
