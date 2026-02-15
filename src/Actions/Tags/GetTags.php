<?php

namespace Spectra\Actions\Tags;

use Spectra\Data\Responses\TagsResponse;
use Spectra\Data\Tag;
use Spectra\Queries\TagsQuery;

class GetTags
{
    public function __construct(
        private readonly TagsQuery $query,
    ) {}

    public function __invoke(): TagsResponse
    {
        return new TagsResponse(
            tags: Tag::fromCollection(($this->query)()),
        );
    }
}
